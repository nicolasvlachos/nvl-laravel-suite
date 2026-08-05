<?php

declare(strict_types=1);

namespace Nvl\Filterable\Exceptions;

use Exception;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Raised when a filter contract or value is invalid.
 */
final class FilterableException extends Exception
{
    /**
     * Create a filter contract exception with machine-readable context.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'invalid_filter',
        public readonly string $path = 'filter',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Convert an HTTP-boundary failure into Laravel's standard 422 response.
     */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->path => [$this->getMessage()],
        ]);
    }
}
