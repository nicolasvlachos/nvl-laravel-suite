<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Values returned by one deterministic definition migration step.
 */
#[Hidden]
final class ContentDefinitionMigrationValuesData extends Data
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $values,
        public readonly array $translations,
        public readonly array $metadata,
    ) {}
}
