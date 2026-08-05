<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Deterministic definition synchronization plan and execution result.
 */
#[TypeScript]
final class ContentDefinitionSyncPlanData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>  $create
     * @param  list<string>  $update
     * @param  list<string>  $unchanged
     * @param  list<string>  $orphan
     */
    public function __construct(
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $create,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $update,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $unchanged,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $orphan,
        public readonly bool $applied,
    ) {}
}
