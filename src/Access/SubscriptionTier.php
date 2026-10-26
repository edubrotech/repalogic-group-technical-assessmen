<?php

declare(strict_types=1);

namespace Repalogic\Access;

use Repalogic\Access\Exceptions\ValidationException;

/**
 * Supported tenant subscription tiers.
 */
enum SubscriptionTier: string
{
    case STANDARD = 'STANDARD';
    case PREMIUM = 'PREMIUM';
    case ENTERPRISE = 'ENTERPRISE';

    /**
     * Parse tier from case-insensitive string value.
     */
    public static function fromString(string $value): self
    {
        $clean = strtoupper(trim($value));

        return match ($clean) {
            'STANDARD' => self::STANDARD,
            'PREMIUM' => self::PREMIUM,
            'ENTERPRISE' => self::ENTERPRISE,
            default => throw new ValidationException(
                "Invalid subscription tier '{$value}'. Allowed: STANDARD, PREMIUM, ENTERPRISE.",
                'subscription_tier'
            ),
        };
    }

    /**
     * Check if this tier is eligible for the overdue grace buffer.
     */
    public function hasOverdueGracePeriod(): bool
    {
        return $this === self::PREMIUM || $this === self::ENTERPRISE;
    }
}
