<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use Repalogic\Settlement\Exceptions\ValidationException;

/**
 * Represents a localized corporate financial account with tier, base currency, and balance.
 */
class Account
{
    public const TIER_STANDARD = 'STANDARD';
    public const TIER_VIP = 'VIP';

    public const SUPPORTED_TIERS = [
        self::TIER_STANDARD,
        self::TIER_VIP,
    ];

    private string $accountId;
    private string $baseCurrency;
    private float $balance;
    private string $tier;

    public function __construct(
        string $accountId,
        string $baseCurrency = 'USD',
        float $balance = 0.0,
        string $tier = self::TIER_STANDARD
    ) {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new ValidationException('Account ID cannot be empty.', 'account_id');
        }

        $baseCurrency = strtoupper(trim($baseCurrency));
        if ($baseCurrency === '') {
            throw new ValidationException('Account base currency cannot be empty.', 'base_currency');
        }

        $tier = strtoupper(trim($tier));
        if (!in_array($tier, self::SUPPORTED_TIERS, true)) {
            throw new ValidationException("Unsupported account tier '{$tier}'. Allowed: STANDARD, VIP.", 'tier');
        }

        $this->accountId = $accountId;
        $this->baseCurrency = $baseCurrency;
        $this->balance = round($balance, 2);
        $this->tier = $tier;
    }

    public function getId(): string
    {
        return $this->accountId;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function getBalance(): float
    {
        return $this->balance;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function isVip(): bool
    {
        return $this->tier === self::TIER_VIP;
    }

    public function credit(float $amount): void
    {
        if ($amount < 0.0) {
            throw new ValidationException('Credit amount cannot be negative.', 'amount');
        }

        $this->balance = round($this->balance + $amount, 2);
    }

    public function debit(float $amount): void
    {
        if ($amount < 0.0) {
            throw new ValidationException('Debit amount cannot be negative.', 'amount');
        }

        $this->balance = round($this->balance - $amount, 2);
    }

    public function setBalance(float $balance): void
    {
        $this->balance = round($balance, 2);
    }

    public function __clone()
    {
        // Primitive values are duplicated by shallow clone
    }
}
