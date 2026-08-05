<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Nvl\Content\Contracts\ContentReferenceResolver;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Deliberately invalid resolver used to verify the reference output boundary.
 */
final class UnsafeReferenceResolver implements ContentReferenceResolver
{
    public function alias(): string
    {
        return 'unsafe';
    }

    public function exists(string $identifier, ContentValidationContext $context): bool
    {
        return true;
    }

    public function display(
        string $identifier,
        ContentValidationContext $context,
    ): array {
        return ['id' => 'spoofed', 'title' => 'Unsafe'];
    }
}
