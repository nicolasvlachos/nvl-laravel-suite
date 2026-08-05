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
 * Validated contract for placing a reusable block on one registered owner.
 */
#[TypeScript]
final class PlaceContentBlockData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly string $key,
        #[TypeScriptOptional]
        public readonly string $region = 'main',
        #[TypeScriptOptional]
        public readonly ?string $parentId = null,
        #[TypeScriptOptional]
        public readonly int $sortOrder = 0,
        #[TypeScriptOptional]
        public readonly bool $isVisible = true,
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
            'key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'region' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'parentId' => ['nullable', 'uuid'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'isVisible' => ['sometimes', 'boolean'],
            'overrides' => ['array', new ContentObjectRule],
        ];
    }
}
