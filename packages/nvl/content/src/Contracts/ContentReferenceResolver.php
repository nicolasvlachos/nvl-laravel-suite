<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Nvl\Content\Validation\ContentValidationContext;

/**
 * Validates and displays one allowlisted reference field target.
 */
interface ContentReferenceResolver
{
    public function alias(): string;

    public function exists(string $identifier, ContentValidationContext $context): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function display(string $identifier, ContentValidationContext $context): ?array;
}
