<?php

declare(strict_types=1);

namespace Repalogic\Settlement\Exceptions;

/**
 * Thrown when an account has insufficient funds or breaches allowed overdraft limits.
 */
class InsufficientFundsException extends SettlementException
{
    public function __construct(
        string $message = 'Insufficient funds for requested transaction.',
        private readonly ?string $accountId = null,
        private readonly ?float $currentBalance = null,
        private readonly ?float $requestedAmount = null,
        private readonly ?string $currency = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($accountId !== null && $currentBalance !== null && $requestedAmount !== null) {
            $formattedCurrency = $currency !== null ? " {$currency}" : '';
            $message = sprintf(
                'Insufficient funds for account %s. Current balance: %.2f%s. Requested deduction: %.2f%s.',
                $accountId,
                $currentBalance,
                $formattedCurrency,
                $requestedAmount,
                $formattedCurrency
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function getCurrentBalance(): ?float
    {
        return $this->currentBalance;
    }

    public function getRequestedAmount(): ?float
    {
        return $this->requestedAmount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }
}
