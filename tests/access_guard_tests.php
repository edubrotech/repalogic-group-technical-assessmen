<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use Repalogic\Access\AccessResult;
use Repalogic\Access\PaymentStatus;
use Repalogic\Access\SubscriptionTier;
use Repalogic\Access\Tenant;
use Repalogic\Access\TenantAccessGuard;
use Repalogic\Access\Exceptions\ValidationException;

/**
 * Runs all TenantAccessGuard test cases and returns structured results.
 *
 * @return array<int, array{name: string, passed: bool, error: ?string}>
 */
function run_access_guard_tests(): array
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

    $guard = new TenantAccessGuard();

    // =========================================================================
    // MANDATORY ASSESSMENT TEST MATRIX (Section 24)
    // =========================================================================

    $matrixCases = [
        ['tier' => 'Standard',   'usage' => 500,  'cap' => 1000, 'status' => 'Active',    'expected' => AccessResult::ALLOWED,        'desc' => 'Standard Active within cap -> ALLOWED'],
        ['tier' => 'Standard',   'usage' => 1001, 'cap' => 1000, 'status' => 'Active',    'expected' => AccessResult::BLOCKED_USAGE,  'desc' => 'Standard Active over cap -> BLOCKED_USAGE'],
        ['tier' => 'Standard',   'usage' => 500,  'cap' => 1000, 'status' => 'Overdue',   'expected' => AccessResult::BLOCKED_STATUS, 'desc' => 'Standard Overdue -> BLOCKED_STATUS'],
        ['tier' => 'Standard',   'usage' => 500,  'cap' => 1000, 'status' => 'Suspended', 'expected' => AccessResult::BLOCKED_STATUS, 'desc' => 'Standard Suspended -> BLOCKED_STATUS'],
        ['tier' => 'Premium',    'usage' => 1000, 'cap' => 1000, 'status' => 'Active',    'expected' => AccessResult::ALLOWED,        'desc' => 'Premium Active at cap -> ALLOWED'],
        ['tier' => 'Premium',    'usage' => 1050, 'cap' => 1000, 'status' => 'Overdue',   'expected' => AccessResult::ALLOWED,        'desc' => 'Premium Overdue within 10% grace (1050/1000) -> ALLOWED'],
        ['tier' => 'Premium',    'usage' => 1100, 'cap' => 1000, 'status' => 'Overdue',   'expected' => AccessResult::ALLOWED,        'desc' => 'Premium Overdue at exact 10% buffer (1100/1000) -> ALLOWED'],
        ['tier' => 'Premium',    'usage' => 1101, 'cap' => 1000, 'status' => 'Overdue',   'expected' => AccessResult::BLOCKED_USAGE,  'desc' => 'Premium Overdue exceeding 10% buffer (1101/1000) -> BLOCKED_USAGE'],
        ['tier' => 'Premium',    'usage' => 500,  'cap' => 1000, 'status' => 'Suspended', 'expected' => AccessResult::BLOCKED_STATUS, 'desc' => 'Premium Suspended -> BLOCKED_STATUS'],
        ['tier' => 'Enterprise', 'usage' => 1050, 'cap' => 1000, 'status' => 'Overdue',   'expected' => AccessResult::ALLOWED,        'desc' => 'Enterprise Overdue within 10% grace (1050/1000) -> ALLOWED'],
        ['tier' => 'Enterprise', 'usage' => 1101, 'cap' => 1000, 'status' => 'Overdue',   'expected' => AccessResult::BLOCKED_USAGE,  'desc' => 'Enterprise Overdue exceeding 10% buffer (1101/1000) -> BLOCKED_USAGE'],
        ['tier' => 'Enterprise', 'usage' => 1001, 'cap' => 1000, 'status' => 'Active',    'expected' => AccessResult::BLOCKED_USAGE,  'desc' => 'Enterprise Active over standard cap -> BLOCKED_USAGE'],
    ];

    foreach ($matrixCases as $index => $c) {
        $test(sprintf('Matrix %d: %s', $index + 1, $c['desc']), function () use ($guard, $c) {
            $result = $guard->evaluateParameters($c['tier'], $c['usage'], $c['cap'], $c['status']);
            assert($result === $c['expected'], sprintf(
                'Expected %s but received %s for %s (%s, usage=%s, cap=%s)',
                $c['expected']->value,
                $result->value,
                $c['desc'],
                $c['tier'],
                $c['usage'],
                $c['cap']
            ));
        });
    }

    // =========================================================================
    // ADDITIONAL POLICY BOUNDARY TESTS
    // =========================================================================

    $test('Policy Boundary: Standard Active exactly at cap (1000/1000) -> ALLOWED', function () use ($guard) {
        $result = $guard->evaluateParameters('STANDARD', 1000, 1000, 'ACTIVE');
        assert($result === AccessResult::ALLOWED);
    });

    $test('Policy Boundary: Enterprise Overdue at exact 110% buffer (1100/1000) -> ALLOWED', function () use ($guard) {
        $result = $guard->evaluateParameters('ENTERPRISE', 1100, 1000, 'OVERDUE');
        assert($result === AccessResult::ALLOWED);
    });

    $test('Policy Boundary: Enterprise Suspended with zero usage -> BLOCKED_STATUS', function () use ($guard) {
        $result = $guard->evaluateParameters('ENTERPRISE', 0, 1000, 'SUSPENDED');
        assert($result === AccessResult::BLOCKED_STATUS);
    });

    $test('Policy Boundary: Zero usage for Active accounts -> ALLOWED', function () use ($guard) {
        assert($guard->evaluateParameters('STANDARD', 0, 500, 'ACTIVE') === AccessResult::ALLOWED);
        assert($guard->evaluateParameters('PREMIUM', 0, 500, 'ACTIVE') === AccessResult::ALLOWED);
        assert($guard->evaluateParameters('ENTERPRISE', 0, 500, 'ACTIVE') === AccessResult::ALLOWED);
    });

    // =========================================================================
    // DATA STRUCTURE & COERCION TESTS
    // =========================================================================

    $test('Interface: Evaluation via typed Tenant object', function () use ($guard) {
        $tenant = new Tenant(
            tier: SubscriptionTier::PREMIUM,
            monthlyUsage: 800,
            tierCap: 1000,
            paymentStatus: PaymentStatus::ACTIVE,
            tenantId: 'TENANT_XYZ'
        );
        $result = $guard->evaluate($tenant);
        assert($result === AccessResult::ALLOWED);
        assert($result->isAllowed() === true);
    });

    $test('Interface: Evaluation via raw array with standard keys', function () use ($guard) {
        $result = $guard->evaluate([
            'tier' => 'Standard',
            'usage' => 600,
            'cap' => 1000,
            'status' => 'Active',
        ]);
        assert($result === AccessResult::ALLOWED);
    });

    $test('Interface: Evaluation via raw array with alternate snake_case keys', function () use ($guard) {
        $result = $guard->evaluate([
            'subscription_tier' => 'Enterprise',
            'monthly_usage' => 1080,
            'tier_cap' => 1000,
            'payment_status' => 'Overdue',
        ]);
        assert($result === AccessResult::ALLOWED);
    });

    $test('Interface: Case-insensitivity across tiers and payment statuses', function () use ($guard) {
        assert($guard->evaluateParameters('standard', 50, 100, 'active') === AccessResult::ALLOWED);
        assert($guard->evaluateParameters('premium', 105, 100, 'overdue') === AccessResult::ALLOWED);
        assert($guard->evaluateParameters('enterprise', 50, 100, 'suspended') === AccessResult::BLOCKED_STATUS);
    });

    // =========================================================================
    // INPUT VALIDATION & DEFENSIVE CHECKS (Section 25)
    // =========================================================================

    $test('Validation: Invalid subscription tier throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('GOLD', 50, 100, 'ACTIVE');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'subscription_tier');
        }
        assert($threw === true);
    });

    $test('Validation: Missing tier in array throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluate(['usage' => 50, 'cap' => 100, 'status' => 'ACTIVE']);
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'tier');
        }
        assert($threw === true);
    });

    $test('Validation: Negative usage throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('STANDARD', -10, 100, 'ACTIVE');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'monthly_usage');
        }
        assert($threw === true);
    });

    $test('Validation: Non-numeric usage throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('STANDARD', 'one_thousand', 100, 'ACTIVE');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'monthly_usage');
        }
        assert($threw === true);
    });

    $test('Validation: Zero cap throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('STANDARD', 10, 0, 'ACTIVE');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'tier_cap');
        }
        assert($threw === true);
    });

    $test('Validation: Negative cap throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('STANDARD', 10, -500, 'ACTIVE');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'tier_cap');
        }
        assert($threw === true);
    });

    $test('Validation: Non-numeric cap throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('STANDARD', 10, 'unlimited', 'ACTIVE');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'tier_cap');
        }
        assert($threw === true);
    });

    $test('Validation: Invalid payment status throws ValidationException', function () use ($guard) {
        $threw = false;
        try {
            $guard->evaluateParameters('STANDARD', 10, 100, 'PENDING');
        } catch (ValidationException $e) {
            $threw = true;
            assert($e->getField() === 'payment_status');
        }
        assert($threw === true);
    });

    return $results;
}

// Allow direct execution from CLI
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running Tenant Access Guard Test Suite...\n\n";
    $results = run_access_guard_tests();
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
    echo sprintf("\nAccess Guard Tests: %d passed out of %d\n", $passed, count($results));
    exit($passed === count($results) ? 0 : 1);
}
