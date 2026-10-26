<?php

declare(strict_types=1);

namespace Repalogic\Access;

use Repalogic\Access\Exceptions\ValidationException;

/**
 * Lightweight policy evaluation engine governing tenant access to high-resource background tasks.
 * Evaluates subscription tier, hard usage cap, and payment status according to corporate policy.
 */
class TenantAccessGuard
{
    public const GRACE_PERIOD_MULTIPLIER = 1.10; // 10% buffer for Overdue Premium/Enterprise

    /**
     * Evaluates a tenant or tenant attribute array against corporate access policies.
     *
     * @param Tenant|array<string, mixed> $tenant
     * @return AccessResult
     * @throws ValidationException
     */
    public function evaluate(Tenant|array $tenant): AccessResult
    {
        $tenantObject = $this->ensureTenantObject($tenant);

        $tier = $tenantObject->getTier();
        $usage = $tenantObject->getMonthlyUsage();
        $cap = $tenantObject->getTierCap();
        $status = $tenantObject->getPaymentStatus();

        // Rule 1: Suspended status across ALL tiers is blocked immediately with BLOCKED_STATUS
        if ($status === PaymentStatus::SUSPENDED) {
            return AccessResult::BLOCKED_STATUS;
        }

        // Rule 2: Standard tier policy
        if ($tier === SubscriptionTier::STANDARD) {
            // Standard accounts are blocked if invoice is overdue, regardless of usage
            if ($status === PaymentStatus::OVERDUE) {
                return AccessResult::BLOCKED_STATUS;
            }

            // Active Standard accounts: check hard cap
            return ($usage <= $cap)
                ? AccessResult::ALLOWED
                : AccessResult::BLOCKED_USAGE;
        }

        // Rule 3: Premium and Enterprise tier policy
        if ($tier->hasOverdueGracePeriod()) {
            if ($status === PaymentStatus::ACTIVE) {
                // Active status enforces standard hard cap
                return ($usage <= $cap)
                    ? AccessResult::ALLOWED
                    : AccessResult::BLOCKED_USAGE;
            }

            if ($status === PaymentStatus::OVERDUE) {
                // Overdue status grants 10% grace period buffer beyond cap
                $allowedLimit = round($cap * self::GRACE_PERIOD_MULTIPLIER, 4);

                return ($usage <= $allowedLimit)
                    ? AccessResult::ALLOWED
                    : AccessResult::BLOCKED_USAGE;
            }
        }

        // Fallback safety (should not be reached due to exhaustive enum checks)
        return AccessResult::BLOCKED_STATUS;
    }

    /**
     * Helper to evaluate raw parameters directly.
     */
    public function evaluateParameters(
        string|SubscriptionTier $tier,
        mixed $monthlyUsage,
        mixed $tierCap,
        string|PaymentStatus $paymentStatus
    ): AccessResult {
        $tenant = new Tenant(
            tier: $tier,
            monthlyUsage: $monthlyUsage,
            tierCap: $tierCap,
            paymentStatus: $paymentStatus
        );

        return $this->evaluate($tenant);
    }

    /**
     * Coerces an associative array into a typed Tenant entity, validating presence of fields.
     *
     * @param Tenant|array<string, mixed> $tenant
     */
    private function ensureTenantObject(Tenant|array $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        if (!is_array($tenant)) {
            throw new ValidationException('Tenant input must be an instance of Tenant or an associative array.');
        }

        $tierKey = $tenant['tier'] ?? $tenant['subscription_tier'] ?? null;
        if ($tierKey === null) {
            throw new ValidationException("Missing required tenant field: 'tier' or 'subscription_tier'.", 'tier');
        }

        $usageKey = $tenant['usage'] ?? $tenant['monthly_usage'] ?? null;
        if ($usageKey === null) {
            throw new ValidationException("Missing required tenant field: 'usage' or 'monthly_usage'.", 'monthly_usage');
        }

        $capKey = $tenant['cap'] ?? $tenant['tier_cap'] ?? null;
        if ($capKey === null) {
            throw new ValidationException("Missing required tenant field: 'cap' or 'tier_cap'.", 'tier_cap');
        }

        $statusKey = $tenant['status'] ?? $tenant['payment_status'] ?? null;
        if ($statusKey === null) {
            throw new ValidationException("Missing required tenant field: 'status' or 'payment_status'.", 'payment_status');
        }

        return new Tenant(
            tier: (string)$tierKey,
            monthlyUsage: $usageKey,
            tierCap: $capKey,
            paymentStatus: (string)$statusKey,
            tenantId: isset($tenant['tenant_id']) ? (string)$tenant['tenant_id'] : null
        );
    }
}
