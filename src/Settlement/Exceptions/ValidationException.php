<?php

declare(strict_types=1);

namespace Repalogic\Settlement\Exceptions;

/**
 * Thrown when business input data or transaction payload fails validation.
 */
class ValidationException extends SettlementException
{
    public function __construct(
        string $message,
        private readonly ?string $field = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getField(): ?string
    {
        return $this->field;
    }
}
