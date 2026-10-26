<?php

declare(strict_types=1);

namespace Repalogic\Settlement;

use Repalogic\Settlement\Exceptions\ValidationException;

/**
 * Manages static or configured foreign exchange rates between currency pairs.
 * Provides deterministic currency conversion and financial rounding.
 */
class ExchangeRateMatrix
{
    /**
     * Storage for rates: [fromCurrency][toCurrency] => rate
     * @var array<string, array<string, float>>
     */
    private array $rates = [];

    /**
     * Storage for direct quoted rates for audit consistency: [pairKey] => quotedRate
     * e.g., 'USD/INR' => 90.00
     * @var array<string, float>
     */
    private array $quotedRates = [];

    /**
     * @param array<string, array<string, float>> $initialRates
     */
    public function __construct(array $initialRates = [])
    {
        // Default standard rates
        $this->setRate('USD', 'USD', 1.0, 1.0);
        $this->setRate('USD', 'INR', 90.0, 90.0);
        $this->setRate('EUR', 'EUR', 1.0, 1.0);
        $this->setRate('INR', 'INR', 1.0, 1.0);

        foreach ($initialRates as $from => $targets) {
            foreach ($targets as $to => $rate) {
                $this->setRate($from, $to, (float)$rate);
            }
        }
    }

    /**
     * Set rate between two currencies.
     * E.g. setRate('USD', 'INR', 90.0) means 1 USD = 90 INR.
     * Converts USD -> INR by multiplying by 90.
     * Converts INR -> USD by dividing by 90 (or multiplying by 1/90).
     */
    public function setRate(string $base, string $quote, float $rate, ?float $quotedRate = null): self
    {
        $base = strtoupper(trim($base));
        $quote = strtoupper(trim($quote));

        if ($rate <= 0.0) {
            throw new ValidationException("Exchange rate must be greater than zero for pair {$base}/{$quote}.");
        }

        $this->rates[$base][$quote] = $rate;
        $this->rates[$quote][$base] = 1.0 / $rate;

        $auditRate = $quotedRate ?? $rate;
        $this->quotedRates["{$base}/{$quote}"] = $auditRate;
        $this->quotedRates["{$quote}/{$base}"] = $auditRate;

        return $this;
    }

    /**
     * Check if a conversion path exists between two currencies.
     */
    public function hasRate(string $from, string $to): bool
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return true;
        }

        return isset($this->rates[$from][$to]);
    }

    /**
     * Convert an amount from one currency to another.
     * Rounds result to 4 decimal places internally, or standard 2 decimals.
     */
    public function convert(float $amount, string $from, string $to, int $precision = 2): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return round($amount, $precision);
        }

        if (!isset($this->rates[$from][$to])) {
            throw new ValidationException("Unsupported currency conversion from '{$from}' to '{$to}'.");
        }

        $multiplier = $this->rates[$from][$to];
        $converted = $amount * $multiplier;

        return round($converted, $precision);
    }

    /**
     * Retrieve the audit-friendly rate applied between two currencies.
     * For example, for INR to USD where 1 USD = 90 INR, returns 90.00.
     */
    public function getAppliedRate(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        $pair1 = "{$from}/{$to}";
        $pair2 = "{$to}/{$from}";

        if (isset($this->quotedRates[$pair1])) {
            return $this->quotedRates[$pair1];
        }

        if (isset($this->quotedRates[$pair2])) {
            return $this->quotedRates[$pair2];
        }

        if (isset($this->rates[$from][$to])) {
            return round($this->rates[$from][$to], 4);
        }

        throw new ValidationException("Exchange rate not configured between '{$from}' and '{$to}'.");
    }
}
