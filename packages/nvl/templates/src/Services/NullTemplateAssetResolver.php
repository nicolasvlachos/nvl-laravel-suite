<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Templates\Contracts\TemplateAssetResolver;

/**
 * Safe default that requires applications to opt into alias-based asset resolution.
 */
final class NullTemplateAssetResolver implements TemplateAssetResolver
{
    public function resolve(string $key): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function scope(string $scope, ?string $type = null): array
    {
        return [];
    }
}
