<?php

declare(strict_types=1);

namespace Nvl\Content\Exceptions;

use InvalidArgumentException;
use Nvl\Content\Enums\ContentResponseCode;

/**
 * Safe transport-neutral representation of invalid content input.
 */
final class InvalidContentException extends ContentException
{
    public static function fromInvalidArgument(InvalidArgumentException $exception): self
    {
        return new self(
            message: $exception->getMessage(),
            responseCode: ContentResponseCode::InvalidContent,
            suggestedStatus: 422,
            previous: $exception,
        );
    }
}
