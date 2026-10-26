<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

/**
 * Representation of an individual audit entry.
 */
class AuditLogEntry
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        public readonly string $transactionId,
        public readonly string $vendorId,
        public readonly string $accountId,
        public readonly float $originalAmount,
        public readonly string $originalCurrency,
        public readonly float $exchangeRate,
        public readonly string $baseCurrency,
        public readonly float $baseImpact,
        public readonly string $transactionType,
        public readonly int $timestampMs,
        public readonly string $status,
        public readonly ?string $failureReason = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'vendor_id' => $this->vendorId,
            'account_id' => $this->accountId,
            'original_amount' => $this->originalAmount,
            'original_currency' => $this->originalCurrency,
            'exchange_rate' => $this->exchangeRate,
            'base_currency' => $this->baseCurrency,
            'base_impact' => $this->baseImpact,
            'transaction_type' => $this->transactionType,
            'timestamp_ms' => $this->timestampMs,
            'status' => $this->status,
            'failure_reason' => $this->failureReason,
        ];
    }
}

/**
 * Maintains the chronological transaction audit log and committed financial ledger.
 */
class AuditLog
{
    /**
     * All attempted operations (both successful and failed).
     * @var AuditLogEntry[]
     */
    private array $entries = [];

    /**
     * Committed financial transactions only.
     * @var AuditLogEntry[]
     */
    private array $ledger = [];

    public function recordSuccess(
        string $transactionId,
        string $vendorId,
        string $accountId,
        float $originalAmount,
        string $originalCurrency,
        float $exchangeRate,
        string $baseCurrency,
        float $baseImpact,
        string $transactionType,
        int $timestampMs
    ): AuditLogEntry {
        $entry = new AuditLogEntry(
            transactionId: $transactionId,
            vendorId: $vendorId,
            accountId: $accountId,
            originalAmount: $originalAmount,
            originalCurrency: $originalCurrency,
            exchangeRate: $exchangeRate,
            baseCurrency: $baseCurrency,
            baseImpact: $baseImpact,
            transactionType: $transactionType,
            timestampMs: $timestampMs,
            status: AuditLogEntry::STATUS_SUCCESS
        );

        $this->entries[] = $entry;
        $this->ledger[] = $entry;

        return $entry;
    }

    public function recordFailure(
        string $transactionId,
        string $vendorId,
        string $accountId,
        float $originalAmount,
        string $originalCurrency,
        float $exchangeRate,
        string $baseCurrency,
        float $baseImpact,
        string $transactionType,
        int $timestampMs,
        string $failureReason
    ): AuditLogEntry {
        $entry = new AuditLogEntry(
            transactionId: $transactionId,
            vendorId: $vendorId,
            accountId: $accountId,
            originalAmount: $originalAmount,
            originalCurrency: $originalCurrency,
            exchangeRate: $exchangeRate,
            baseCurrency: $baseCurrency,
            baseImpact: $baseImpact,
            transactionType: $transactionType,
            timestampMs: $timestampMs,
            status: AuditLogEntry::STATUS_FAILED,
            failureReason: $failureReason
        );

        $this->entries[] = $entry;

        return $entry;
    }

    /**
     * @return AuditLogEntry[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getLastEntry(): ?AuditLogEntry
    {
        if (empty($this->entries)) {
            return null;
        }

        return $this->entries[array_key_last($this->entries)];
    }

    /**
     * @return AuditLogEntry[]
     */
    public function getLedger(): array
    {
        return $this->ledger;
    }

    public function getLastLedgerEntry(): ?AuditLogEntry
    {
        if (empty($this->ledger)) {
            return null;
        }

        return $this->ledger[array_key_last($this->ledger)];
    }

    public function getLedgerCount(): int
    {
        return count($this->ledger);
    }

    public function getEntryCount(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->ledger = [];
    }

    public function __clone()
    {
        // Entries are immutable Value Objects, array copy is sufficient
    }
}
