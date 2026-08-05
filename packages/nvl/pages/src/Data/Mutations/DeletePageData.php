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
 * Soft-delete contract protected by an exact page revision.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class DeletePageData extends Data
{
    use DataTransform;

    /**
     * Create one validated page deletion payload.
     */
    public function __construct(
        public readonly int $expectedRevision,
    ) {}

    /**
     * Return the validation rules for a page deletion.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
