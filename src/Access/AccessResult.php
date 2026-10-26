<?php

declare(strict_types=1);

namespace Repalogic\Access;

/**
 * Access evaluation results for tenant background job execution.
 */
enum AccessResult: string
{
    case ALLOWED = 'ALLOWED';
    case BLOCKED_STATUS = 'BLOCKED_STATUS';
    case BLOCKED_USAGE = 'BLOCKED_USAGE';

    public function isAllowed(): bool
    {
        return $this === self::ALLOWED;
    }

    public function isBlockedStatus(): bool
    {
        return $this === self::BLOCKED_STATUS;
    }

    public function isBlockedUsage(): bool
    {
        return $this === self::BLOCKED_USAGE;
    }
}
