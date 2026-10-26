<?php

declare(strict_types=1);

namespace Repalogic\Settlement\Exceptions;

/**
 * Thrown when an external vendor is blocked because their circuit breaker is tripped (OPEN).
 */
class CircuitBreakerException extends SettlementException
{
    public function __construct(
        private readonly string $vendorId,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $message = sprintf(
                'Circuit breaker is OPEN for vendor "%s". All transactions are blocked.',
                $vendorId
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public function getVendorId(): string
    {
        return $this->vendorId;
    }
}
