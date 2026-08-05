<?php

declare(strict_types=1);

namespace Nvl\Templates\Exceptions;

/**
 * Raised when an optimistic-lock token no longer matches persisted state.
 */
final class StaleTemplateException extends TemplatesException
{
    public static function forResource(string $resource, string $identifier): self
    {
        return new self("The {$resource} [{$identifier}] changed after it was read.");
    }
}
