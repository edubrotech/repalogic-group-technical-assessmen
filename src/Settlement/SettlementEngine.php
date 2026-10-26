<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use Repalogic\Settlement\Exceptions\CircuitBreakerException;
use Repalogic\Settlement\Exceptions\InsufficientFundsException;
use Repalogic\Settlement\Exceptions\SettlementException;
use Repalogic\Settlement\Exceptions\ValidationException;
use Throwable;

/**
 * High-velocity core execution engine for corporate transaction settlement.
 * Handles sequential transactions, multi-currency conversion, VIP overdraft & velocity,
 * vendor circuit breakers, in-memory ledger/audit logging, and atomic batch rollbacks.
 */
class SettlementEngine
{
    public const VIP_HARD_OVERDRAFT_LIMIT = -500.00;
    public const STANDARD_MIN_BALANCE = 0.00;
    public const VELOCITY_WINDOW_MS = 60000; // 60 seconds rolling window
    public const MAX_VIP_DEDUCTIONS_IN_WINDOW = 3;

    /**
     * In-memory accounts: [accountId] => Account
     * @var array<string, Account>
     */
    private array $accounts = [];

    /**
     * In-memory vendor state machines: [vendorId] => VendorState
     * @var array<string, VendorState>
     */
    private array $vendors = [];

    /**
     * In-memory history of successful deduction timestamps: [accountId] => int[]
     * @var array<string, int[]>
     */
    private array $deductionTimestamps = [];

    private ExchangeRateMatrix $exchangeRateMatrix;
    private AuditLog $auditLog;

    public function __construct(
        ?ExchangeRateMatrix $exchangeRateMatrix = null,
        ?AuditLog $auditLog = null
    ) {
        $this->exchangeRateMatrix = $exchangeRateMatrix ?? new ExchangeRateMatrix();
        $this->auditLog = $auditLog ?? new AuditLog();
    }

    public function addAccount(Account $account): self
    {
        $this->accounts[$account->getId()] = $account;
        return $this;
    }

    public function getAccount(string $accountId): ?Account
    {
        return $this->accounts[$accountId] ?? null;
    }

    public function hasAccount(string $accountId): bool
    {
        return isset($this->accounts[$accountId]);
    }

    public function getVendorState(string $vendorId): VendorState
    {
        if (!isset($this->vendors[$vendorId])) {
            $this->vendors[$vendorId] = new VendorState($vendorId);
        }

        return $this->vendors[$vendorId];
    }

    public function getExchangeRateMatrix(): ExchangeRateMatrix
    {
        return $this->exchangeRateMatrix;
    }

    public function setExchangeRateMatrix(ExchangeRateMatrix $matrix): self
    {
        $this->exchangeRateMatrix = $matrix;
        return $this;
    }

    public function getAuditLog(): AuditLog
    {
        return $this->auditLog;
    }

    /**
     * Process an individual incoming transaction.
     *
     * @param Transaction|array<string, mixed> $transaction
     * @throws CircuitBreakerException
     * @throws InsufficientFundsException
     * @throws ValidationException
     * @throws SettlementException
     */
    public function process(Transaction|array $transaction): AuditLogEntry
    {
        $tx = $this->ensureTransactionObject($transaction);
        $vendorId = $tx->getVendorId();
        $accountId = $tx->getAccountId();
        $timestampMs = $tx->getTimestampMs();
        $vendor = $this->getVendorState($vendorId);

        // 1. Circuit Breaker Check (MUST be checked before account validation or balance operations)
        if ($vendor->isCircuitOpen()) {
            $this->auditLog->recordFailure(
                transactionId: $tx->getTransactionId(),
                vendorId: $vendorId,
                accountId: $accountId,
                originalAmount: $tx->getAmount(),
                originalCurrency: $tx->getCurrency(),
                exchangeRate: 0.0,
                baseCurrency: 'UNKNOWN',
                baseImpact: 0.0,
                transactionType: $tx->getTransactionType(),
                timestampMs: $timestampMs,
                failureReason: "Circuit breaker is OPEN for vendor '{$vendorId}'."
            );

            throw new CircuitBreakerException($vendorId);
        }

        // 2. Account Existence Validation
        if (!isset($this->accounts[$accountId])) {
            $vendor->recordFailure($timestampMs);
            $reason = "Account '{$accountId}' not found.";

            $this->auditLog->recordFailure(
                transactionId: $tx->getTransactionId(),
                vendorId: $vendorId,
                accountId: $accountId,
                originalAmount: $tx->getAmount(),
                originalCurrency: $tx->getCurrency(),
                exchangeRate: 0.0,
                baseCurrency: 'UNKNOWN',
                baseImpact: 0.0,
                transactionType: $tx->getTransactionType(),
                timestampMs: $timestampMs,
                failureReason: $reason
            );

            throw new ValidationException($reason, 'account_id');
        }

        $account = $this->accounts[$accountId];
        $baseCurrency = $account->getBaseCurrency();
        $txCurrency = $tx->getCurrency();

        // 3. Currency Conversion Validation
        if (!$this->exchangeRateMatrix->hasRate($txCurrency, $baseCurrency)) {
            $vendor->recordFailure($timestampMs);
            $reason = "Unsupported currency conversion from '{$txCurrency}' to '{$baseCurrency}'.";

            $this->auditLog->recordFailure(
                transactionId: $tx->getTransactionId(),
                vendorId: $vendorId,
                accountId: $accountId,
                originalAmount: $tx->getAmount(),
                originalCurrency: $txCurrency,
                exchangeRate: 0.0,
                baseCurrency: $baseCurrency,
                baseImpact: 0.0,
                transactionType: $tx->getTransactionType(),
                timestampMs: $timestampMs,
                failureReason: $reason
            );

            throw new ValidationException($reason, 'currency');
        }

        $baseAmount = $this->exchangeRateMatrix->convert($tx->getAmount(), $txCurrency, $baseCurrency, 2);
        $appliedRate = $this->exchangeRateMatrix->getAppliedRate($txCurrency, $baseCurrency);

        // 4. Handle Top-up
        if ($tx->isTopUp()) {
            $account->credit($baseAmount);
            $vendor->recordSuccess();

            return $this->auditLog->recordSuccess(
                transactionId: $tx->getTransactionId(),
                vendorId: $vendorId,
                accountId: $accountId,
                originalAmount: $tx->getAmount(),
                originalCurrency: $txCurrency,
                exchangeRate: $appliedRate,
                baseCurrency: $baseCurrency,
                baseImpact: $baseAmount,
                transactionType: $tx->getTransactionType(),
                timestampMs: $timestampMs
            );
        }

        // 5. Handle Deduction (Balance integrity, VIP Overdraft, and Velocity Protection)
        $currentBalance = $account->getBalance();
        $newBalance = round($currentBalance - $baseAmount, 2);

        // Evaluate VIP Velocity & Overdraft Eligibility
        $minAllowedBalance = self::STANDARD_MIN_BALANCE;

        if ($account->isVip()) {
            // Count deductions in rolling 60s window: [T - 60000, T] inclusive
            $windowStart = $timestampMs - self::VELOCITY_WINDOW_MS;
            $history = $this->deductionTimestamps[$accountId] ?? [];
            $deductionsInWindow = 0;

            foreach ($history as $prevTs) {
                if ($prevTs >= $windowStart && $prevTs <= $timestampMs) {
                    $deductionsInWindow++;
                }
            }

            // If account has already executed 3 deductions, this 4th deduction breaches threshold (>3).
            // Thus overdraft capability is revoked for this deduction.
            if ($deductionsInWindow < self::MAX_VIP_DEDUCTIONS_IN_WINDOW) {
                $minAllowedBalance = self::VIP_HARD_OVERDRAFT_LIMIT;
            } else {
                $minAllowedBalance = self::STANDARD_MIN_BALANCE;
            }
        }

        // Balance Check
        if ($newBalance < $minAllowedBalance) {
            $vendor->recordFailure($timestampMs);
            $reason = sprintf(
                'Insufficient funds for account %s. Current: %.2f %s. Requested deduction: %.2f %s (min allowed: %.2f %s).',
                $accountId,
                $currentBalance,
                $baseCurrency,
                $baseAmount,
                $baseCurrency,
                $minAllowedBalance,
                $baseCurrency
            );

            $this->auditLog->recordFailure(
                transactionId: $tx->getTransactionId(),
                vendorId: $vendorId,
                accountId: $accountId,
                originalAmount: $tx->getAmount(),
                originalCurrency: $txCurrency,
                exchangeRate: $appliedRate,
                baseCurrency: $baseCurrency,
                baseImpact: -$baseAmount,
                transactionType: $tx->getTransactionType(),
                timestampMs: $timestampMs,
                failureReason: $reason
            );

            throw new InsufficientFundsException(
                message: $reason,
                accountId: $accountId,
                currentBalance: $currentBalance,
                requestedAmount: $baseAmount,
                currency: $baseCurrency
            );
        }

        // Deduction is approved: mutate account balance and track velocity
        $account->debit($baseAmount);
        $this->deductionTimestamps[$accountId][] = $timestampMs;
        $vendor->recordSuccess();

        return $this->auditLog->recordSuccess(
            transactionId: $tx->getTransactionId(),
            vendorId: $vendorId,
            accountId: $accountId,
            originalAmount: $tx->getAmount(),
            originalCurrency: $txCurrency,
            exchangeRate: $appliedRate,
            baseCurrency: $baseCurrency,
            baseImpact: -$baseAmount,
            transactionType: $tx->getTransactionType(),
            timestampMs: $timestampMs
        );
    }

    /**
     * Executes an atomic batch of transactions.
     * If ANY transaction fails, all state changes (balances, ledger, audit, vendor counters,
     * and velocity timestamps) are completely rolled back to the pre-batch state.
     *
     * @param array<Transaction|array<string, mixed>> $transactions
     * @return AuditLogEntry[]
     * @throws Throwable
     */
    public function processBatch(array $transactions): array
    {
        $snapshot = $this->createSnapshot();
        $processedEntries = [];

        try {
            foreach ($transactions as $tx) {
                $processedEntries[] = $this->process($tx);
            }
            return $processedEntries;
        } catch (Throwable $e) {
            $this->restoreSnapshot($snapshot);
            throw $e;
        }
    }

    /**
     * Creates a complete deep-copy snapshot of engine state for atomic rollback.
     * @return array<string, mixed>
     */
    public function createSnapshot(): array
    {
        $accountsCopy = [];
        foreach ($this->accounts as $id => $acc) {
            $accountsCopy[$id] = clone $acc;
        }

        $vendorsCopy = [];
        foreach ($this->vendors as $id => $v) {
            $vendorsCopy[$id] = clone $v;
        }

        return [
            'accounts' => $accountsCopy,
            'vendors' => $vendorsCopy,
            'deductionTimestamps' => $this->deductionTimestamps,
            'auditLog' => clone $this->auditLog,
        ];
    }

    /**
     * Restores state from a previously captured snapshot.
     * @param array<string, mixed> $snapshot
     */
    public function restoreSnapshot(array $snapshot): void
    {
        $this->accounts = $snapshot['accounts'];
        $this->vendors = $snapshot['vendors'];
        $this->deductionTimestamps = $snapshot['deductionTimestamps'];
        $this->auditLog = $snapshot['auditLog'];
    }

    /**
     * Convert an associative array to a Transaction object if needed.
     * @param Transaction|array<string, mixed> $transaction
     */
    private function ensureTransactionObject(Transaction|array $transaction): Transaction
    {
        if ($transaction instanceof Transaction) {
            return $transaction;
        }

        if (!is_array($transaction)) {
            throw new ValidationException('Transaction must be an instance of Transaction or an associative array.');
        }

        $requiredKeys = ['vendor_id', 'account_id', 'amount', 'currency', 'timestamp_ms', 'transaction_type'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $transaction)) {
                throw new ValidationException("Missing required transaction field: '{$key}'.", $key);
            }
        }

        return new Transaction(
            vendorId: (string)$transaction['vendor_id'],
            accountId: (string)$transaction['account_id'],
            amount: (float)$transaction['amount'],
            currency: (string)$transaction['currency'],
            timestampMs: (int)$transaction['timestamp_ms'],
            transactionType: (string)$transaction['transaction_type'],
            transactionId: isset($transaction['transaction_id']) ? (string)$transaction['transaction_id'] : null
        );
    }
}
