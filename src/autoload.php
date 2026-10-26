<?php

declare(strict_types=1);

/**
 * Lightweight, zero-dependency PSR-4 compliant autoloader for Repalogic Assessment.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Repalogic\\';
    $baseDir = __DIR__ . '/';

    // Check if the class uses the Repalogic namespace prefix
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    // Fallback for non-namespaced classes if directly instantiated
    $directMap = [
        'SettlementEngine' => __DIR__ . '/Settlement/SettlementEngine.php',
        'Account' => __DIR__ . '/Settlement/Account.php',
        'Transaction' => __DIR__ . '/Settlement/Transaction.php',
        'ExchangeRateMatrix' => __DIR__ . '/Settlement/ExchangeRateMatrix.php',
        'VendorState' => __DIR__ . '/Settlement/VendorState.php',
        'AuditLog' => __DIR__ . '/Settlement/AuditLog.php',
        'AuditLogEntry' => __DIR__ . '/Settlement/AuditLog.php',
        'SettlementException' => __DIR__ . '/Settlement/Exceptions/SettlementException.php',
        'InsufficientFundsException' => __DIR__ . '/Settlement/Exceptions/InsufficientFundsException.php',
        'CircuitBreakerException' => __DIR__ . '/Settlement/Exceptions/CircuitBreakerException.php',
        'ValidationException' => __DIR__ . '/Settlement/Exceptions/ValidationException.php',
        'TenantAccessGuard' => __DIR__ . '/Access/TenantAccessGuard.php',
        'Tenant' => __DIR__ . '/Access/Tenant.php',
        'SubscriptionTier' => __DIR__ . '/Access/SubscriptionTier.php',
        'PaymentStatus' => __DIR__ . '/Access/PaymentStatus.php',
        'AccessResult' => __DIR__ . '/Access/AccessResult.php',
    ];

    if (isset($directMap[$class]) && file_exists($directMap[$class])) {
        require_once $directMap[$class];
        // Create an alias in root namespace if not already defined
        $namespaced = 'Repalogic\\' . (str_contains($directMap[$class], 'Access') ? 'Access\\' : 'Settlement\\') . $class;
        if (str_contains($directMap[$class], 'Exceptions')) {
            $namespaced = 'Repalogic\\Settlement\\Exceptions\\' . $class;
        }
        if (class_exists($namespaced, false) || enum_exists($namespaced, false)) {
            class_alias($namespaced, $class);
        }
    }
});
