<?php

declare(strict_types=1);

namespace Nvl\Content\Schema;

/**
 * Validated internal source contract before preset expansion and schema compilation.
 */
final readonly class ContentDefinitionSource
{
    /**
     * @param  array<array-key, mixed>  $schema
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $allowedScopes
     * @param  list<string>  $allowedRegions
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public string $category,
        public int $version,
        public ?string $view,
        public array $schema,
        public array $defaults = [],
        public array $allowedScopes = ['global'],
        public array $allowedRegions = ['main'],
        public bool $isActive = true,
        public int $sortOrder = 0,
    ) {}
}
