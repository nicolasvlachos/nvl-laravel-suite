<?php

declare(strict_types=1);

namespace Nvl\Primitives\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Reports an invalid value at a primitive construction boundary.
 */
final class InvalidPrimitive extends InvalidArgumentException
{
    /**
     * Create a field-specific invalid value exception.
     */
    public static function for(
        string $primitive,
        string $reason,
        ?Throwable $previous = null,
    ): self {
        return new self("Invalid {$primitive}: {$reason}", previous: $previous);
    }
}
