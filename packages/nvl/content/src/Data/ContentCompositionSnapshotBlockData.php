<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use InvalidArgumentException;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One strongly typed immutable block record inside a composition snapshot.
 */
#[MapInputName(SnakeCaseMapper::class)]
#[MapOutputName(SnakeCaseMapper::class)]
#[TypeScript]
final class ContentCompositionSnapshotBlockData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly string $placementId,
        public readonly ?string $parentId,
        public readonly string $key,
        public readonly string $region,
        public readonly int $sortOrder,
        public readonly string $blockId,
        public readonly string $definitionKey,
        public readonly ContentSchemaData $definitionSchema,
        public readonly ?string $definitionView,
        public readonly ContentVisibility $visibility,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $values,
        #[LiteralTypeScriptType('Record<string, Record<string, unknown>>')]
        public readonly array $translations,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $overrides,
        public readonly int $blockRevision,
        public readonly int $placementRevision,
    ) {
        if ($this->placementId === ''
            || $this->key === ''
            || $this->region === ''
            || $this->blockId === ''
            || $this->definitionKey === ''
            || $this->sortOrder < 0
            || $this->blockRevision < 1
            || $this->placementRevision < 1) {
            throw new InvalidArgumentException(
                'Content composition snapshot block identity is invalid.',
            );
        }
    }
}
