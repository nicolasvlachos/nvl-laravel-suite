<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Allowlisted profile management query.
 */
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class SeoProfileQuery extends Data
{
    public function __construct(
        public readonly ?string $scope = null,
        public readonly ?string $status = null,
        public readonly ?string $ownerAlias = null,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'ownerAlias' => ['nullable', 'string', 'max:120'],
            'page' => ['integer', 'min:1'],
            'perPage' => ['integer', 'min:1', 'max:200'],
        ];
    }
}
