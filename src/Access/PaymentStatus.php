<?php

declare(strict_types=1);

namespace Repalogic\Access;

use Repalogic\Access\Exceptions\ValidationException;

/**
 * Tenant invoice payment status.
 */
enum PaymentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case OVERDUE = 'OVERDUE';
    case SUSPENDED = 'SUSPENDED';

    /**
     * Parse payment status from case-insensitive string value.
     */
    public static function fromString(string $value): self
    {
        $clean = strtoupper(trim($value));

        return match ($clean) {
            'ACTIVE' => self::ACTIVE,
            'OVERDUE' => self::OVERDUE,
            'SUSPENDED' => self::SUSPENDED,
            default => throw new ValidationException(
                "Invalid payment status '{$value}'. Allowed: ACTIVE, OVERDUE, SUSPENDED.",
                'payment_status'
            ),
        };
    }
}
