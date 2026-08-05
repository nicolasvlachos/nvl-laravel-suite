<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete typed bootstrap contract for a consumer-owned content editor.
 */
#[TypeScript]
final class ContentEditorData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentDefinitionData>  $definitions
     * @param  list<ContentFieldPresetData>  $presets
     * @param  list<string>  $groups
     * @param  list<ContentPlacementData>  $placements
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $group,
        #[DataCollectionOf(ContentDefinitionData::class)]
        public readonly array $definitions,
        #[DataCollectionOf(ContentFieldPresetData::class)]
        public readonly array $presets,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $groups,
        #[DataCollectionOf(ContentPlacementData::class)]
        public readonly array $placements,
    ) {}
}
