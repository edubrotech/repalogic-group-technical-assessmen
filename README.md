# Repalogic Group — Senior Backend Engineer Technical Assessment

Production-grade, zero-dependency, pure in-memory PHP implementation of the **Analytical Logic Challenge** (`SettlementEngine`) and **Core Logic & Policy Challenge** (`TenantAccessGuard`).

---

## 1. Executive Summary

This repository contains the complete implementation for the Repalogic Group Senior Backend Engineer Technical Assessment. It strictly adheres to all assessment mandates:
* **Zero Frameworks**: No Laravel, Symfony, Doctrine, or external packages.
* **Zero Dependencies**: Pure PHP 8.x with native arrays and object-oriented design.
* **In-Memory State**: No external database (MySQL, PostgreSQL, Redis). All state resides cleanly in-memory.
* **Native Test Execution**: Standalone CLI test suite (`run_tests.php`) with zero external test frameworks (no PHPUnit, Pest).

---

## 2. Project Directory Structure

```text
.
├── src/
│   ├── autoload.php                   # PSR-4 compliant native autoloader
│   │
│   ├── Settlement/                    # Engine A: Analytical Financial Settlement
│   │   ├── SettlementEngine.php       # Core sequential and batch settlement engine
│   │   ├── Account.php                # Account entity (balances, tiers, mutations)
│   │   ├── Transaction.php            # Transaction Value Object (positive amounts, types)
│   │   ├── ExchangeRateMatrix.php     # Multi-currency matrix with bi-directional rates
│   │   ├── VendorState.php            # Vendor circuit breaker state machine
│   │   ├── AuditLog.php               # Dual-store: Audit Log and Committed Ledger
│   │   └── Exceptions/
│   │       ├── SettlementException.php       # Root settlement domain exception
│   │       ├── InsufficientFundsException.php # Overdraft & balance limit violations
│   │       ├── ValidationException.php        # Input validation failure
│   │       └── CircuitBreakerException.php    # Tripped vendor circuit breaker
│   │
│   └── Access/                        # Engine B: Tenant Access Policy Guard
│       ├── TenantAccessGuard.php      # Rule engine for background automations
│       ├── Tenant.php                 # Tenant entity with usage, tier, and status
│       ├── SubscriptionTier.php       # STANDARD, PREMIUM, ENTERPRISE backed enum
│       ├── PaymentStatus.php          # ACTIVE, OVERDUE, SUSPENDED backed enum
│       ├── AccessResult.php           # ALLOWED, BLOCKED_STATUS, BLOCKED_USAGE enum
│       └── Exceptions/
│           └── ValidationException.php # Access domain validation exception
│
├── tests/
│   ├── settlement_tests.php           # 23 settlement test cases (Scenarios 1-4 + edges)
│   └── access_guard_tests.php         # 28 tenant access guard test cases
│
├── run_tests.php                      # Master test runner CLI script
├── ARCHITECTURE.md                    # Deep-dive 18-point architectural documentation
└── README.md                          # Quickstart, documentation, and verification
```

---

## 3. Quickstart & Test Execution

Run the master test suite using native PHP CLI:

```bash
php run_tests.php
```

### Individual Test Suites
You can also execute each test suite independently:

```bash
# Run Settlement Engine tests only
php tests/settlement_tests.php

# Run Tenant Access Guard tests only
php tests/access_guard_tests.php
```

### Verified Test Output

```text
========================================================================
REPALOGIC GROUP — TECHNICAL ASSESSMENT TEST RUNNER
PHP Version: 8.4.5 | In-Memory Zero-Framework Architecture
========================================================================

--- Settlement Engine Test Suite (Challenge A) ---
 [PASS] Scenario 1: Standard Currency Conversion & Ledger Tracking
 [PASS] Scenario 2: The VIP Velocity Trap (>3 deductions in 60s revokes overdraft)
 [PASS] Scenario 3: Cascading Vendor Circuit Breaker
 [PASS] Scenario 4: Atomic Batch Rollback (Analytical Integrity)
 [PASS] Edge 1: Standard account exactly reaches zero
 [PASS] Edge 2: Standard account attempts to go below zero
 [PASS] Edge 3: VIP reaches exactly -500.00
 [PASS] Edge 4: VIP attempts to exceed -500.00
 [PASS] Edge 5 & 6: Third deduction in velocity window succeeds and fourth fails
 [PASS] Edge 7: Deduction outside the rolling 60s window restores overdraft
 [PASS] Edge 8: Successful vendor transaction resets consecutive failure count
 [PASS] Edge 9 & 10: Circuit breaker remains open and rejects subsequent transactions without failure accumulation
 [PASS] Edge 11: Unsupported currency throws ValidationException
 [PASS] Edge 12: Zero transaction amount throws ValidationException
 [PASS] Edge 13: Negative transaction amount throws ValidationException
 [PASS] Edge 14: Invalid transaction type throws ValidationException
 [PASS] Edge 15: Unknown account throws ValidationException
 [PASS] Edge 16: Unknown vendor dynamically registers clean state
 [PASS] Edge 17: Top-up transaction increases balance and has positive impact
 [PASS] Edge 18: Currency conversion precision
 [PASS] Edge 19: Multiple accounts sharing same vendor contribute to shared failure count
 [PASS] Edge 20: Multiple vendors on same account are isolated
 [PASS] Edge 21, 22, 23: Batch failure restores vendor, velocity, and audit/ledger state

--- Tenant Access Guard Test Suite (Challenge B) ---
 [PASS] Matrix 1: Standard Active within cap -> ALLOWED
 [PASS] Matrix 2: Standard Active over cap -> BLOCKED_USAGE
 [PASS] Matrix 3: Standard Overdue -> BLOCKED_STATUS
 [PASS] Matrix 4: Standard Suspended -> BLOCKED_STATUS
 [PASS] Matrix 5: Premium Active at cap -> ALLOWED
 [PASS] Matrix 6: Premium Overdue within 10% grace (1050/1000) -> ALLOWED
 [PASS] Matrix 7: Premium Overdue at exact 10% buffer (1100/1000) -> ALLOWED
 [PASS] Matrix 8: Premium Overdue exceeding 10% buffer (1101/1000) -> BLOCKED_USAGE
 [PASS] Matrix 9: Premium Suspended -> BLOCKED_STATUS
 [PASS] Matrix 10: Enterprise Overdue within 10% grace (1050/1000) -> ALLOWED
 [PASS] Matrix 11: Enterprise Overdue exceeding 10% buffer (1101/1000) -> BLOCKED_USAGE
 [PASS] Matrix 12: Enterprise Active over standard cap -> BLOCKED_USAGE
 [PASS] Policy Boundary: Standard Active exactly at cap (1000/1000) -> ALLOWED
 [PASS] Policy Boundary: Enterprise Overdue at exact 110% buffer (1100/1000) -> ALLOWED
 [PASS] Policy Boundary: Enterprise Suspended with zero usage -> BLOCKED_STATUS
 [PASS] Policy Boundary: Zero usage for Active accounts -> ALLOWED
 [PASS] Interface: Evaluation via typed Tenant object
 [PASS] Interface: Evaluation via raw array with standard keys
 [PASS] Interface: Evaluation via raw array with alternate snake_case keys
 [PASS] Interface: Case-insensitivity across tiers and payment statuses
 [PASS] Validation: Invalid subscription tier throws ValidationException
 [PASS] Validation: Missing tier in array throws ValidationException
 [PASS] Validation: Negative usage throws ValidationException
 [PASS] Validation: Non-numeric usage throws ValidationException
 [PASS] Validation: Zero cap throws ValidationException
 [PASS] Validation: Negative cap throws ValidationException
 [PASS] Validation: Non-numeric cap throws ValidationException
 [PASS] Validation: Invalid payment status throws ValidationException

========================================================================
ALL TESTS PASSED! (51/51 passed in 5.71ms)
========================================================================
```

---

## 4. Engine A: Settlement Engine

### Key Features
1. **Multi-Currency Conversion**: Converts incoming transaction amounts into the account's base currency via `ExchangeRateMatrix`. Preserves original amount, currency, applied rate, and base impact in the audit log.
2. **VIP Overdraft Limit**: Standard accounts are protected from dropping below $0.00. VIP accounts are granted overdraft down to -$500.00 USD.
3. **VIP Velocity Protection**: If a VIP account attempts **more than 3 deductions within any rolling 60-second window ($60,000\text{ ms}$)**, overdraft protection is immediately revoked, and balance cannot drop below $0.00.
4. **Vendor Circuit Breaker**: If a vendor accumulates **3 consecutive failed transactions**, the circuit breaker trips to `OPEN`. Subsequent transactions are blocked immediately with `CircuitBreakerException` before balance validation. Successful transactions reset the failure count to 0.
5. **Atomic Batch Rollbacks**: `processBatch()` wraps execution in an in-memory snapshot. If any transaction in a batch fails, account balances, vendor failure counters, velocity timestamps, and audit/ledger records are rolled back completely.

### Usage Example

```php
use Repalogic\Settlement\Account;
use Repalogic\Settlement\ExchangeRateMatrix;
use Repalogic\Settlement\SettlementEngine;
use Repalogic\Settlement\Transaction;

$matrix = new ExchangeRateMatrix();
$matrix->setRate('USD', 'INR', 90.0, 90.0);

$engine = new SettlementEngine($matrix);
$account = new Account('ACC_001', 'USD', 100.00, Account::TIER_STANDARD);
$engine->addAccount($account);

$tx = new Transaction(
    vendorId: 'VENDOR_A',
    accountId: 'ACC_001',
    amount: 4500.0,
    currency: 'INR',
    timestampMs: 1700000000000,
    transactionType: 'Deduction'
);

$entry = $engine->process($tx);
echo "New Balance: " . $account->getBalance() . " USD\n"; // 50.00 USD
```

---

## 5. Engine B: Tenant Access Guard

### Policy Matrix & Grace Period Rules
* **Suspended**: Blocked immediately across all tiers (`BLOCKED_STATUS`), regardless of usage.
* **Standard Tier**:
  * Overdue $\rightarrow$ `BLOCKED_STATUS` (even if within cap).
  * Active within cap $\rightarrow$ `ALLOWED`.
  * Active exceeding cap $\rightarrow$ `BLOCKED_USAGE`.
* **Premium & Enterprise Tiers**:
  * Active within cap $\rightarrow$ `ALLOWED`.
  * Active exceeding cap $\rightarrow$ `BLOCKED_USAGE`.
  * Overdue within 10% grace buffer ($\le \text{cap} \times 1.10$) $\rightarrow$ `ALLOWED`.
  * Overdue exceeding 10% grace buffer ($> \text{cap} \times 1.10$) $\rightarrow$ `BLOCKED_USAGE`.

### Usage Example

```php
use Repalogic\Access\AccessResult;
use Repalogic\Access\PaymentStatus;
use Repalogic\Access\SubscriptionTier;
use Repalogic\Access\Tenant;
use Repalogic\Access\TenantAccessGuard;

$guard = new TenantAccessGuard();

$tenant = new Tenant(
    tier: SubscriptionTier::PREMIUM,
    monthlyUsage: 1050,
    tierCap: 1000,
    paymentStatus: PaymentStatus::OVERDUE
);

$result = $guard->evaluate($tenant);
echo "Access Result: " . $result->value . "\n"; // ALLOWED (within 10% grace buffer)
```

---

## 6. Architecture & Design Principles

For complete architectural details, complexity analysis, error handling hierarchies, and production evolution roadmap, refer to [ARCHITECTURE.md](ARCHITECTURE.md).
