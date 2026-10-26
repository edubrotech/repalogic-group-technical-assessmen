<?php

declare(strict_types=1);

namespace Repalogic\Access;

use Repalogic\Access\Exceptions\ValidationException;

/**
 * Encapsulates tenant account state for access evaluation.
 */
class Tenant
{
    private SubscriptionTier $tier;
    private float $monthlyUsage;
    private float $tierCap;
    private PaymentStatus $paymentStatus;
    private ?string $tenantId;

    public function __construct(
        string|SubscriptionTier $tier,
        mixed $monthlyUsage,
        mixed $tierCap,
        string|PaymentStatus $paymentStatus,
        ?string $tenantId = null
    ) {
        $this->tier = $tier instanceof SubscriptionTier
            ? $tier
            : SubscriptionTier::fromString((string)$tier);

        if (!is_numeric($monthlyUsage)) {
            throw new ValidationException('Monthly usage must be a numeric value.', 'monthly_usage');
        }

        $numericUsage = (float)$monthlyUsage;
        if ($numericUsage < 0.0) {
            throw new ValidationException('Monthly usage cannot be negative.', 'monthly_usage');
        }
        $this->monthlyUsage = round($numericUsage, 4);

        if (!is_numeric($tierCap)) {
            throw new ValidationException('Tier cap must be a numeric value.', 'tier_cap');
        }

        $numericCap = (float)$tierCap;
        if ($numericCap <= 0.0) {
            throw new ValidationException('Tier cap must be strictly greater than zero.', 'tier_cap');
        }
        $this->tierCap = round($numericCap, 4);

        $this->paymentStatus = $paymentStatus instanceof PaymentStatus
            ? $paymentStatus
            : PaymentStatus::fromString((string)$paymentStatus);

        $this->tenantId = $tenantId;
    }

    public function getTier(): SubscriptionTier
    {
        return $this->tier;
    }

    public function getMonthlyUsage(): float
    {
        return $this->monthlyUsage;
    }

    public function getTierCap(): float
    {
        return $this->tierCap;
    }

    public function getPaymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }
}
