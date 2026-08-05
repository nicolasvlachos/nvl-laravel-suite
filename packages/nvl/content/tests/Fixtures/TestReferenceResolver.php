<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Nvl\Content\Contracts\ContentReferenceResolver;
use Nvl\Content\Validation\ContentValidationContext;

final class TestReferenceResolver implements ContentReferenceResolver
{
    public function alias(): string
    {
        return 'article';
    }

    public function exists(string $identifier, ContentValidationContext $context): bool
    {
        return $identifier === 'article-1';
    }

    public function display(
        string $identifier,
        ContentValidationContext $context,
    ): ?array {
        return $this->exists($identifier, $context)
            ? [
                'title' => "Article ({$context->locale})",
                'owner_id' => $context->owner?->getKey(),
                'group' => $context->group,
                'path' => $context->path,
                'public_only' => $context->publicOnly,
                'visibility' => $context->visibility->value,
            ]
            : null;
    }
}
