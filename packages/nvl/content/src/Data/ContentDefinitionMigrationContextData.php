<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Immutable server-side input supplied to one definition migration step.
 */
#[Hidden]
final class ContentDefinitionMigrationContextData extends Data
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $blockId,
        public readonly string $blockKey,
        public readonly string $scope,
        public readonly string $scopeKey,
        public readonly ContentStatus $status,
        public readonly ContentVisibility $visibility,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly array $values,
        public readonly array $translations,
        public readonly array $metadata,
    ) {}
}
