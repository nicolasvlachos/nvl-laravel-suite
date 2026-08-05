<?php

declare(strict_types=1);

namespace Nvl\Pages\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Reparents and reorders one page with optimistic concurrency.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MovePageData extends Data
{
    use DataTransform;

    /**
     * Create one validated page move payload.
     */
    public function __construct(
        public readonly ?string $parentId,
        public readonly int $position,
        public readonly int $expectedRevision,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'parentId' => ['nullable', 'uuid'],
            'position' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
