<?php

declare(strict_types=1);

namespace Nvl\Content\Data\Mutations;

use Nvl\Content\Validation\ContentObjectRule;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Revision-safe contract for moving, ordering, hiding, or overriding a placement.
 */
#[TypeScript]
final class UpdateContentPlacementData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly int $expectedRevision,
        public readonly string $region,
        public readonly ?string $parentId,
        public readonly int $sortOrder,
        public readonly bool $isVisible,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $overrides = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'region' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'parentId' => ['nullable', 'uuid'],
            'sortOrder' => ['required', 'integer', 'min:0'],
            'isVisible' => ['required', 'boolean'],
            'overrides' => ['array', new ContentObjectRule],
        ];
    }
}
