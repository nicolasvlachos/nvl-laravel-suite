<?php

declare(strict_types=1);

namespace Nvl\Templates\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Templates\Contracts\TemplateOwnerResolver;

/**
 * Resolves the isolation-test owner alias.
 */
final class TestTemplateOwnerResolver implements TemplateOwnerResolver
{
    public function alias(): string
    {
        return 'member';
    }

    public function resolve(string $identifier): ?Model
    {
        return TestTemplateOwner::query()->find($identifier);
    }
}
