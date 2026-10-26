<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use Repalogic\Settlement\Account;
use Repalogic\Settlement\ExchangeRateMatrix;
use Repalogic\Settlement\SettlementEngine;
use Repalogic\Settlement\Transaction;
use Repalogic\Settlement\Exceptions\CircuitBreakerException;
use Repalogic\Settlement\Exceptions\InsufficientFundsException;
use Repalogic\Settlement\Exceptions\ValidationException;

/**
 * Runs all settlement test cases and returns structured results.
 *
 * @return array<int, array{name: string, passed: bool, error: ?string}>
 */
function run_settlement_tests(): array
{
    $results = [];

    $test = function (string $name, callable $callable) use (&$results): void {
        try {
            $callable();
            $results[] = ['name' => $name, 'passed' => true, 'error' => null];
        } catch (\Throwable $e) {
            $results[] = [
                'name' => $name,
                'passed' => false,
                'error' => sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()),
            ];
        }
    };

    // =========================================================================
    // MANDATORY ASSESSMENT SCENARIOS (Scenarios 1 - 4)
    // =========================================================================

    $test('Scenario 1: Standard Currency Conversion & Ledger Tracking', function () {
        $matrix = new ExchangeRateMatrix();
        $matrix->setRate('USD', 'INR', 90.0, 90.0);

        $engine = new SettlementEngine($matrix);
        $accountA = new Account('ACC_A', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($accountA);

        $tx = new Transaction(
            vendorId: 'VENDOR_1',
            accountId: 'ACC_A',
            amount: 4500.0,
            currency: 'INR',
            timestampMs: 1000,
            transactionType: 'Deduction',
            transactionId: 'TX_S1'
        );

        $entry = $engine->process($tx);

        assert($accountA->getBalance() === 50.00, 'Account A balance must be exactly 50.00 USD');
        assert($entry->originalAmount === 4500.0, 'Audit log original amount must be 4500');
        assert($entry->originalCurrency === 'INR', 'Audit log original currency must be INR');
        assert($entry->exchangeRate === 90.0, 'Audit log exchange rate must be 90.00');
        assert($entry->baseImpact === -50.00, 'Audit log base impact must be -50.00 USD');
        assert($entry->baseCurrency === 'USD', 'Audit log base currency must be USD');
        assert($entry->status === 'SUCCESS', 'Audit log status must be SUCCESS');

        $ledger = $engine->getAuditLog()->getLedger();
        assert(count($ledger) === 1, 'Ledger must contain exactly 1 committed transaction');
        assert($ledger[0]->transactionId === 'TX_S1', 'Ledger entry transaction ID matches');
    });

    $test('Scenario 2: The VIP Velocity Trap (>3 deductions in 60s revokes overdraft)', function () {
        $engine = new SettlementEngine();
        $accountB = new Account('ACC_B', 'USD', 0.00, Account::TIER_VIP);
        $engine->addAccount($accountB);

        // Deduction 1 at 1000ms: succeeds -> balance -100
        $engine->process(new Transaction('V1', 'ACC_B', 100.0, 'USD', 1000, 'Deduction'));
        assert($accountB->getBalance() === -100.00, 'Balance after 1st deduction must be -100');

        // Deduction 2 at 5000ms: succeeds -> balance -200
        $engine->process(new Transaction('V1', 'ACC_B', 100.0, 'USD', 5000, 'Deduction'));
        assert($accountB->getBalance() === -200.00, 'Balance after 2nd deduction must be -200');

        // Deduction 3 at 12000ms: succeeds -> balance -300
        $engine->process(new Transaction('V1', 'ACC_B', 100.0, 'USD', 12000, 'Deduction'));
        assert($accountB->getBalance() === -300.00, 'Balance after 3rd deduction must be -300');

        // Deduction 4 at 20000ms: must fail with InsufficientFundsException because velocity breached (>3 in 60s)
        $caught = false;
        try {
            $engine->process(new Transaction('V1', 'ACC_B', 50.0, 'USD', 20000, 'Deduction'));
        } catch (InsufficientFundsException $e) {
            $caught = true;
        }

        assert($caught === true, '4th deduction must throw InsufficientFundsException due to revoked overdraft');
        assert($accountB->getBalance() === -300.00, 'Account B balance must remain -300.00 USD after failed 4th deduction');
        assert($engine->getAuditLog()->getLedgerCount() === 3, 'Ledger must only contain the 3 successful transactions');
    });

    $test('Scenario 3: Cascading Vendor Circuit Breaker', function () {
        $engine = new SettlementEngine();
        $accC = new Account('ACC_C', 'USD', 10.00, Account::TIER_STANDARD);
        $accD = new Account('ACC_D', 'USD', 10.00, Account::TIER_STANDARD);
        $accE = new Account('ACC_E', 'USD', 10.00, Account::TIER_STANDARD);
        $accF = new Account('ACC_F', 'USD', 10.00, Account::TIER_STANDARD);

        $engine->addAccount($accC);
        $engine->addAccount($accD);
        $engine->addAccount($accE);
        $engine->addAccount($accF);

        $vendorX = 'VENDOR_X';

        // 1. Account C: Deduct 50 -> Fails (1st failure)
        try {
            $engine->process(new Transaction($vendorX, 'ACC_C', 50.0, 'USD', 1000, 'Deduction'));
            assert(false, 'Expected failure on Account C');
        } catch (InsufficientFundsException $e) {
            // expected
        }
        assert($engine->getVendorState($vendorX)->getConsecutiveFailures() === 1, 'Vendor failure count must be 1');
        assert($engine->getVendorState($vendorX)->isCircuitOpen() === false, 'Circuit must remain CLOSED');

        // 2. Account D: Deduct 20 -> Fails (2nd failure)
        try {
            $engine->process(new Transaction($vendorX, 'ACC_D', 20.0, 'USD', 2000, 'Deduction'));
            assert(false, 'Expected failure on Account D');
        } catch (InsufficientFundsException $e) {
            // expected
        }
        assert($engine->getVendorState($vendorX)->getConsecutiveFailures() === 2, 'Vendor failure count must be 2');
        assert($engine->getVendorState($vendorX)->isCircuitOpen() === false, 'Circuit must remain CLOSED');

        // 3. Account E: Deduct 15 -> Fails (3rd failure -> Circuit trips to OPEN!)
        try {
            $engine->process(new Transaction($vendorX, 'ACC_E', 15.0, 'USD', 3000, 'Deduction'));
            assert(false, 'Expected failure on Account E');
        } catch (InsufficientFundsException $e) {
            // expected
        }
        assert($engine->getVendorState($vendorX)->getConsecutiveFailures() === 3, 'Vendor failure count must be 3');
        assert($engine->getVendorState($vendorX)->isCircuitOpen() === true, 'Circuit must now be OPEN');

        // 4. Account F: Deduct 2 -> Must fail with CircuitBreakerException before balance check
        $circuitCaught = false;
        try {
            $engine->process(new Transaction($vendorX, 'ACC_F', 2.0, 'USD', 4000, 'Deduction'));
        } catch (CircuitBreakerException $e) {
            $circuitCaught = true;
            assert($e->getVendorId() === $vendorX, 'Exception must report vendor ID');
        }

        assert($circuitCaught === true, 'Account F transaction must throw CircuitBreakerException');
        assert($accF->getBalance() === 10.00, 'Account F balance must remain untouched at 10.00 USD');
        assert($engine->getVendorState($vendorX)->getConsecutiveFailures() === 3, 'Failure count must not recursively increment');
    });

    $test('Scenario 4: Atomic Batch Rollback (Analytical Integrity)', function () {
        $engine = new SettlementEngine();
        $accountG = new Account('ACC_G', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($accountG);

        $initialLedgerCount = $engine->getAuditLog()->getLedgerCount();

        $batch = [
            new Transaction('V1', 'ACC_G', 40.0, 'USD', 1000, 'Deduction', 'TX_B1'),
            new Transaction('V1', 'ACC_G', 50.0, 'USD', 2000, 'Deduction', 'TX_B2'),
            new Transaction('V1', 'ACC_G', 30.0, 'USD', 3000, 'Deduction', 'TX_B3'), // 40+50+30 = 120 > 100 -> fails!
        ];

        $batchFailed = false;
        try {
            $engine->processBatch($batch);
        } catch (InsufficientFundsException $e) {
            $batchFailed = true;
        }

        assert($batchFailed === true, 'Batch must fail with InsufficientFundsException on 3rd transaction');
        assert($accountG->getBalance() === 100.00, 'Account G balance must be rolled back to 100.00 USD');
        assert($engine->getAuditLog()->getLedgerCount() === $initialLedgerCount, 'No ledger transactions should remain');
    });

    // =========================================================================
    // ADDITIONAL SETTLEMENT EDGE CASES (Section 18)
    // =========================================================================

    $test('Edge 1: Standard account exactly reaches zero', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_ZERO', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        $engine->process(new Transaction('V1', 'ACC_ZERO', 100.0, 'USD', 1000, 'Deduction'));
        assert($acc->getBalance() === 0.00, 'Standard account can reach exactly 0.00');
    });

    $test('Edge 2: Standard account attempts to go below zero', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_NEG', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        $threw = false;
        try {
            $engine->process(new Transaction('V1', 'ACC_NEG', 100.01, 'USD', 1000, 'Deduction'));
        } catch (InsufficientFundsException $e) {
            $threw = true;
        }
        assert($threw === true, 'Must throw InsufficientFundsException');
        assert($acc->getBalance() === 100.00, 'Balance must remain unchanged');
    });

    $test('Edge 3: VIP reaches exactly -500.00', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_VIP_500', 'USD', 0.00, Account::TIER_VIP);
        $engine->addAccount($acc);

        $engine->process(new Transaction('V1', 'ACC_VIP_500', 500.0, 'USD', 1000, 'Deduction'));
        assert($acc->getBalance() === -500.00, 'VIP balance can reach exactly -500.00');
    });

    $test('Edge 4: VIP attempts to exceed -500.00', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_VIP_OVER', 'USD', 0.00, Account::TIER_VIP);
        $engine->addAccount($acc);

        $threw = false;
        try {
            $engine->process(new Transaction('V1', 'ACC_VIP_OVER', 500.01, 'USD', 1000, 'Deduction'));
        } catch (InsufficientFundsException $e) {
            $threw = true;
        }
        assert($threw === true, 'Must throw InsufficientFundsException when exceeding -500');
        assert($acc->getBalance() === 0.00, 'Balance must remain unchanged');
    });

    $test('Edge 5 & 6: Third deduction in velocity window succeeds and fourth fails', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_VIP_V', 'USD', 0.00, Account::TIER_VIP);
        $engine->addAccount($acc);

        $engine->process(new Transaction('V1', 'ACC_VIP_V', 10.0, 'USD', 10000, 'Deduction'));
        $engine->process(new Transaction('V1', 'ACC_VIP_V', 10.0, 'USD', 20000, 'Deduction'));
        $engine->process(new Transaction('V1', 'ACC_VIP_V', 10.0, 'USD', 30000, 'Deduction'));
        assert($acc->getBalance() === -30.00, 'Third deduction succeeds');

        $threw = false;
        try {
            $engine->process(new Transaction('V1', 'ACC_VIP_V', 10.0, 'USD', 40000, 'Deduction'));
        } catch (InsufficientFundsException $e) {
            $threw = true;
        }
        assert($threw === true, 'Fourth deduction fails velocity check');
        assert($acc->getBalance() === -30.00, 'Balance must remain unchanged at -30');
    });

    $test('Edge 7: Deduction outside the rolling 60s window restores overdraft', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_WINDOW', 'USD', 0.00, Account::TIER_VIP);
        $engine->addAccount($acc);

        // 3 deductions inside early window
        $engine->process(new Transaction('V1', 'ACC_WINDOW', 50.0, 'USD', 1000, 'Deduction'));
        $engine->process(new Transaction('V1', 'ACC_WINDOW', 50.0, 'USD', 2000, 'Deduction'));
        $engine->process(new Transaction('V1', 'ACC_WINDOW', 50.0, 'USD', 3000, 'Deduction'));
        assert($acc->getBalance() === -150.00);

        // 4th deduction at 70,000ms: window is [10,000, 70,000].
        // Earlier deductions at 1000, 2000, 3000 are outside the window!
        // Count in window is 0, so overdraft is permitted!
        $engine->process(new Transaction('V1', 'ACC_WINDOW', 50.0, 'USD', 70000, 'Deduction'));
        assert($acc->getBalance() === -200.00, '4th deduction succeeded because earlier deductions aged out');
    });

    $test('Edge 8: Successful vendor transaction resets consecutive failure count', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_RESET', 'USD', 50.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        $v = 'V_RESET';

        // 2 consecutive failures
        try { $engine->process(new Transaction($v, 'ACC_RESET', 100.0, 'USD', 1000, 'Deduction')); } catch (InsufficientFundsException) {}
        try { $engine->process(new Transaction($v, 'ACC_RESET', 100.0, 'USD', 2000, 'Deduction')); } catch (InsufficientFundsException) {}
        assert($engine->getVendorState($v)->getConsecutiveFailures() === 2);

        // 1 successful transaction resets counter
        $engine->process(new Transaction($v, 'ACC_RESET', 10.0, 'USD', 3000, 'Deduction'));
        assert($engine->getVendorState($v)->getConsecutiveFailures() === 0, 'Counter must be reset to 0');
        assert($engine->getVendorState($v)->isCircuitOpen() === false);

        // Another 2 failures do not trip circuit (total consecutive is 2, not 4)
        try { $engine->process(new Transaction($v, 'ACC_RESET', 100.0, 'USD', 4000, 'Deduction')); } catch (InsufficientFundsException) {}
        try { $engine->process(new Transaction($v, 'ACC_RESET', 100.0, 'USD', 5000, 'Deduction')); } catch (InsufficientFundsException) {}
        assert($engine->getVendorState($v)->getConsecutiveFailures() === 2);
        assert($engine->getVendorState($v)->isCircuitOpen() === false, 'Circuit must remain closed');
    });

    $test('Edge 9 & 10: Circuit breaker remains open and rejects subsequent transactions without failure accumulation', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_CB_STAY', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);
        $v = 'V_CB';

        // Force 3 failures
        for ($i = 0; $i < 3; $i++) {
            try { $engine->process(new Transaction($v, 'ACC_CB_STAY', 500.0, 'USD', 1000 * ($i + 1), 'Deduction')); } catch (InsufficientFundsException) {}
        }
        assert($engine->getVendorState($v)->isCircuitOpen() === true);

        // Attempt 4th and 5th
        for ($i = 0; $i < 2; $i++) {
            $threw = false;
            try {
                $engine->process(new Transaction($v, 'ACC_CB_STAY', 10.0, 'USD', 5000 + $i, 'Deduction'));
            } catch (CircuitBreakerException) {
                $threw = true;
            }
            assert($threw === true, 'Subsequent attempts must throw CircuitBreakerException');
        }
        assert($engine->getVendorState($v)->getConsecutiveFailures() === 3, 'Consecutive failure count stays at 3');
    });

    $test('Edge 11: Unsupported currency throws ValidationException', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_CURR', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        $threw = false;
        try {
            $engine->process(new Transaction('V1', 'ACC_CURR', 10.0, 'XYZ', 1000, 'Deduction'));
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'currency');
        }
        assert($threw === true, 'Unsupported currency must throw ValidationException');
    });

    $test('Edge 12: Zero transaction amount throws ValidationException', function () {
        $threw = false;
        try {
            new Transaction('V1', 'ACC_1', 0.0, 'USD', 1000, 'Deduction');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'amount');
        }
        assert($threw === true, 'Zero amount must throw ValidationException');
    });

    $test('Edge 13: Negative transaction amount throws ValidationException', function () {
        $threw = false;
        try {
            new Transaction('V1', 'ACC_1', -50.0, 'USD', 1000, 'Deduction');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'amount');
        }
        assert($threw === true, 'Negative amount must throw ValidationException');
    });

    $test('Edge 14: Invalid transaction type throws ValidationException', function () {
        $threw = false;
        try {
            new Transaction('V1', 'ACC_1', 50.0, 'USD', 1000, 'Transfer');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'transaction_type');
        }
        assert($threw === true, 'Invalid type must throw ValidationException');
    });

    $test('Edge 15: Unknown account throws ValidationException', function () {
        $engine = new SettlementEngine();
        $threw = false;
        try {
            $engine->process(new Transaction('V1', 'NON_EXISTENT', 10.0, 'USD', 1000, 'Deduction'));
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'account_id');
        }
        assert($threw === true, 'Unknown account must throw ValidationException');
    });

    $test('Edge 16: Unknown vendor dynamically registers clean state', function () {
        $engine = new SettlementEngine();
        $state = $engine->getVendorState('BRAND_NEW_VENDOR');
        assert($state->getConsecutiveFailures() === 0);
        assert($state->isCircuitOpen() === false);
    });

    $test('Edge 17: Top-up transaction increases balance and has positive impact', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_TOP', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        $entry = $engine->process(new Transaction('V1', 'ACC_TOP', 50.0, 'USD', 1000, 'Top-up'));
        assert($acc->getBalance() === 150.00, 'Balance must increase by 50');
        assert($entry->baseImpact === 50.00, 'Base impact must be positive 50');
        assert($entry->status === 'SUCCESS');
    });

    $test('Edge 18: Currency conversion precision', function () {
        $matrix = new ExchangeRateMatrix();
        $matrix->setRate('USD', 'EUR', 0.92, 0.92); // 1 USD = 0.92 EUR => 1 EUR = 1/0.92 USD = 1.086956...
        $engine = new SettlementEngine($matrix);
        $acc = new Account('ACC_EUR', 'USD', 100.00, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        // Deduct 46 EUR: 46 / 0.92 = 50.00 USD
        $entry = $engine->process(new Transaction('V1', 'ACC_EUR', 46.0, 'EUR', 1000, 'Deduction'));
        assert($entry->baseImpact === -50.00, '46 EUR / 0.92 must equal exactly 50 USD');
        assert($acc->getBalance() === 50.00);
    });

    $test('Edge 19: Multiple accounts sharing same vendor contribute to shared failure count', function () {
        $engine = new SettlementEngine();
        $acc1 = new Account('A1', 'USD', 5.0, Account::TIER_STANDARD);
        $acc2 = new Account('A2', 'USD', 5.0, Account::TIER_STANDARD);
        $engine->addAccount($acc1)->addAccount($acc2);

        $v = 'SHARED_V';
        try { $engine->process(new Transaction($v, 'A1', 10.0, 'USD', 1000, 'Deduction')); } catch (InsufficientFundsException) {}
        try { $engine->process(new Transaction($v, 'A2', 10.0, 'USD', 2000, 'Deduction')); } catch (InsufficientFundsException) {}
        assert($engine->getVendorState($v)->getConsecutiveFailures() === 2);
    });

    $test('Edge 20: Multiple vendors on same account are isolated', function () {
        $engine = new SettlementEngine();
        $acc = new Account('SHARED_ACC', 'USD', 10.0, Account::TIER_STANDARD);
        $engine->addAccount($acc);

        $vBad = 'V_BAD';
        $vGood = 'V_GOOD';

        // Trip bad vendor
        for ($i = 0; $i < 3; $i++) {
            try { $engine->process(new Transaction($vBad, 'SHARED_ACC', 50.0, 'USD', 1000 * ($i + 1), 'Deduction')); } catch (InsufficientFundsException) {}
        }
        assert($engine->getVendorState($vBad)->isCircuitOpen() === true);

        // Good vendor can still transact on SHARED_ACC
        $entry = $engine->process(new Transaction($vGood, 'SHARED_ACC', 5.0, 'USD', 5000, 'Deduction'));
        assert($entry->status === 'SUCCESS');
        assert($acc->getBalance() === 5.0);
    });

    $test('Edge 21, 22, 23: Batch failure restores vendor, velocity, and audit/ledger state', function () {
        $engine = new SettlementEngine();
        $acc = new Account('ACC_SNAP', 'USD', 100.0, Account::TIER_VIP);
        $engine->addAccount($acc);
        $v = 'V_SNAP';

        // Pre-batch state: 1 successful transaction
        $engine->process(new Transaction($v, 'ACC_SNAP', 10.0, 'USD', 1000, 'Deduction'));
        assert($acc->getBalance() === 90.0);
        $preBatchLedgerCount = $engine->getAuditLog()->getLedgerCount();
        $preBatchFailures = $engine->getVendorState($v)->getConsecutiveFailures();

        // Failing batch
        $batch = [
            new Transaction($v, 'ACC_SNAP', 20.0, 'USD', 2000, 'Deduction'),
            new Transaction($v, 'ACC_SNAP', 200.0, 'USD', 3000, 'Deduction'), // 90 - 20 - 200 = -130 (allowed for VIP)
            new Transaction($v, 'ACC_SNAP', 500.0, 'USD', 4000, 'Deduction'), // -130 - 500 = -630 (exceeds -500 -> FAILS!)
        ];

        $failed = false;
        try {
            $engine->processBatch($batch);
        } catch (InsufficientFundsException) {
            $failed = true;
        }

        assert($failed === true, 'Batch must fail');
        assert($acc->getBalance() === 90.0, 'Balance must be restored to 90.0');
        assert($engine->getAuditLog()->getLedgerCount() === $preBatchLedgerCount, 'Ledger count restored');
        assert($engine->getVendorState($v)->getConsecutiveFailures() === $preBatchFailures, 'Vendor state restored');

        // Velocity restored: acc can still do 2 more deductions within window
        $engine->process(new Transaction($v, 'ACC_SNAP', 10.0, 'USD', 5000, 'Deduction'));
        $engine->process(new Transaction($v, 'ACC_SNAP', 10.0, 'USD', 6000, 'Deduction'));
        assert($acc->getBalance() === 70.0, 'Velocity history properly restored allowing subsequent deductions');
    });

    return $results;
}

// Allow direct execution from CLI
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running Settlement Engine Test Suite...\n\n";
    $results = run_settlement_tests();
    $passed = 0;
    foreach ($results as $res) {
        if ($res['passed']) {
            $passed++;
            echo " [PASS] " . $res['name'] . "\n";
        } else {
            echo " [FAIL] " . $res['name'] . "\n";
            echo "        " . $res['error'] . "\n";
        }
    }
    echo sprintf("\nSettlement Tests: %d passed out of %d\n", $passed, count($results));
    exit($passed === count($results) ? 0 : 1);
}
