<?php

declare(strict_types=1);

namespace Nvl\Pages\Data\Queries;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated site and pagination boundary for page management listings.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PageIndexQueryData extends Data
{
    use DataTransform;

    /**
     * Create a bounded page management query.
     */
    public function __construct(
        public readonly string $site,
        public readonly int $perPage = 25,
    ) {}

    /**
     * Return page management query validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'site' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
