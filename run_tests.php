<?php

declare(strict_types=1);

/**
 * Repalogic Group — Master Test Runner
 *
 * Executes the complete test suite for:
 * 1. SettlementEngine (Analytical Logic Challenge)
 * 2. TenantAccessGuard (Core Logic & Policy Challenge)
 *
 * Usage:
 *   php run_tests.php
 */

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/tests/settlement_tests.php';
require_once __DIR__ . '/tests/access_guard_tests.php';

// ANSI terminal color codes (safely ignored if terminal doesn't support them)
$supportsColor = DIRECTORY_SEPARATOR === '/' || (function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(STDOUT));
$cGreen  = $supportsColor ? "\033[32m" : '';
$cRed    = $supportsColor ? "\033[31m" : '';
$cCyan   = $supportsColor ? "\033[36m" : '';
$cYellow = $supportsColor ? "\033[33m" : '';
$cBold   = $supportsColor ? "\033[1m"  : '';
$cReset  = $supportsColor ? "\033[0m"  : '';

$startTime = microtime(true);

echo "\n{$cBold}========================================================================{$cReset}\n";
echo "{$cBold}REPALOGIC GROUP — TECHNICAL ASSESSMENT TEST RUNNER{$cReset}\n";
echo "PHP Version: " . PHP_VERSION . " | In-Memory Zero-Framework Architecture\n";
echo "{$cBold}========================================================================{$cReset}\n\n";

$allPassed = true;
$totalTests = 0;
$totalPassed = 0;
$totalFailed = 0;

// 1. Run Settlement Engine Tests
echo "{$cBold}{$cCyan}--- Settlement Engine Test Suite (Challenge A) ---{$cReset}\n";
$settlementResults = run_settlement_tests();
foreach ($settlementResults as $res) {
    $totalTests++;
    if ($res['passed']) {
        $totalPassed++;
        echo " {$cGreen}[PASS]{$cReset} " . $res['name'] . "\n";
    } else {
        $totalFailed++;
        $allPassed = false;
        echo " {$cRed}[FAIL]{$cReset} " . $res['name'] . "\n";
        echo "        {$cRed}Error: " . $res['error'] . "{$cReset}\n";
    }
}

echo "\n";

// 2. Run Tenant Access Guard Tests
echo "{$cBold}{$cCyan}--- Tenant Access Guard Test Suite (Challenge B) ---{$cReset}\n";
$accessResults = run_access_guard_tests();
foreach ($accessResults as $res) {
    $totalTests++;
    if ($res['passed']) {
        $totalPassed++;
        echo " {$cGreen}[PASS]{$cReset} " . $res['name'] . "\n";
    } else {
        $totalFailed++;
        $allPassed = false;
        echo " {$cRed}[FAIL]{$cReset} " . $res['name'] . "\n";
        echo "        {$cRed}Error: " . $res['error'] . "{$cReset}\n";
    }
}

$durationMs = round((microtime(true) - $startTime) * 1000, 2);

echo "\n{$cBold}========================================================================{$cReset}\n";
if ($allPassed) {
    echo "{$cBold}{$cGreen}ALL TESTS PASSED! ({$totalPassed}/{$totalTests} passed in {$durationMs}ms){$cReset}\n";
    echo "{$cBold}========================================================================{$cReset}\n\n";
    exit(0);
} else {
    echo "{$cBold}{$cRed}TEST SUITE FAILED! ({$totalPassed} passed, {$totalFailed} failed out of {$totalTests}){$cReset}\n";
    echo "{$cBold}========================================================================{$cReset}\n\n";
    exit(1);
}
