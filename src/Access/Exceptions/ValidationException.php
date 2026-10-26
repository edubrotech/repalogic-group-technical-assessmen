<?php

declare(strict_types=1);

namespace Repalogic\Access\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when tenant evaluation input parameters fail validation.
 */
class ValidationException extends InvalidArgumentException
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
