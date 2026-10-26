<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use Repalogic\Settlement\Exceptions\ValidationException;

/**
 * Immutable transaction data object representing an incoming financial operation.
 */
class Transaction
{
    public const TYPE_TOP_UP = 'Top-up';
    public const TYPE_DEDUCTION = 'Deduction';

    public const SUPPORTED_TYPES = [
        self::TYPE_TOP_UP,
        self::TYPE_DEDUCTION,
    ];

    private string $transactionId;
    private string $vendorId;
    private string $accountId;
    private float $amount;
    private string $currency;
    private int $timestampMs;
    private string $transactionType;

    public function __construct(
        string $vendorId,
        string $accountId,
        float $amount,
        string $currency,
        int $timestampMs,
        string $transactionType,
        ?string $transactionId = null
    ) {
        $vendorId = trim($vendorId);
        if ($vendorId === '') {
            throw new ValidationException('Vendor ID cannot be empty.', 'vendor_id');
        }

        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new ValidationException('Account ID cannot be empty.', 'account_id');
        }

        if ($amount <= 0.0) {
            throw new ValidationException('Transaction amount must be strictly greater than zero.', 'amount');
        }

        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw new ValidationException('Transaction currency cannot be empty.', 'currency');
        }

        if ($timestampMs < 0) {
            throw new ValidationException('Transaction timestamp must be non-negative.', 'timestamp_ms');
        }

        $normalizedType = self::normalizeType($transactionType);

        $this->transactionId = $transactionId ?? uniqid('tx_', true);
        $this->vendorId = $vendorId;
        $this->accountId = $accountId;
        $this->amount = round($amount, 4);
        $this->currency = $currency;
        $this->timestampMs = $timestampMs;
        $this->transactionType = $normalizedType;
    }

    public static function normalizeType(string $type): string
    {
        $clean = trim($type);
        foreach (self::SUPPORTED_TYPES as $supported) {
            if (strcasecmp($clean, $supported) === 0) {
                return $supported;
            }
        }

        throw new ValidationException(
            "Unsupported transaction type '{$type}'. Allowed: 'Top-up', 'Deduction'.",
            'transaction_type'
        );
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getVendorId(): string
    {
        return $this->vendorId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTimestampMs(): int
    {
        return $this->timestampMs;
    }

    public function getTransactionType(): string
    {
        return $this->transactionType;
    }

    public function isDeduction(): bool
    {
        return $this->transactionType === self::TYPE_DEDUCTION;
    }

    public function isTopUp(): bool
    {
        return $this->transactionType === self::TYPE_TOP_UP;
    }
}
