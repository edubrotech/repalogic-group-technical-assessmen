<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

/**
 * Tracks reliability and circuit breaker status for an individual API vendor.
 */
class VendorState
{
    public const FAILURE_THRESHOLD = 3;

    public const STATE_CLOSED = 'CLOSED';
    public const STATE_OPEN = 'OPEN';

    private string $vendorId;
    private int $consecutiveFailures = 0;
    private string $state = self::STATE_CLOSED;
    private ?int $trippedAtMs = null;

    public function __construct(string $vendorId)
    {
        $this->vendorId = $vendorId;
    }

    public function getVendorId(): string
    {
        return $this->vendorId;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isCircuitOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    /**
     * Called when a transaction for this vendor succeeds.
     * Resets the consecutive failure counter to zero.
     */
    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
    }

    /**
     * Called when a transaction fails (e.g. insufficient funds, validation error).
     * If the circuit is already OPEN, this call is a no-op to prevent state corruption.
     * If consecutive failures reaches the threshold (3), trips the circuit breaker to OPEN.
     */
    public function recordFailure(?int $timestampMs = null): void
    {
        if ($this->isCircuitOpen()) {
            return;
        }

        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= self::FAILURE_THRESHOLD) {
            $this->state = self::STATE_OPEN;
            $this->trippedAtMs = $timestampMs;
        }
    }

    /**
     * Manually trip circuit breaker (for testing or administrative override).
     */
    public function trip(?int $timestampMs = null): void
    {
        $this->state = self::STATE_OPEN;
        $this->trippedAtMs = $timestampMs;
    }

    /**
     * Reset circuit breaker to CLOSED (for recovery/admin).
     */
    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->consecutiveFailures = 0;
        $this->trippedAtMs = null;
    }

    public function __clone()
    {
        // Primitive values are duplicated by shallow clone
    }
}
