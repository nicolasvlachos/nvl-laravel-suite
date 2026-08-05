<?php

declare(strict_types=1);

namespace Nvl\Templates\Contracts;

/**
 * Resolves stable Media/application asset aliases for class-based templates.
 */
interface TemplateAssetResolver
{
    public function resolve(string $key): ?string;

    /**
     * @return array<string, string>
     */
    public function scope(string $scope, ?string $type = null): array;
}
