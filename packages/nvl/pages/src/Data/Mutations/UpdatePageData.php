<?php

declare(strict_types=1);

namespace Nvl\Pages\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete editable page replacement protected by an exact revision.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class UpdatePageData extends Data
{
    use DataTransform;

    /**
     * Create one validated complete page replacement payload.
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function __construct(
        public readonly string $slug,
        public readonly PageKind $kind,
        public readonly ?string $resource,
        public readonly PageStatus $status,
        public readonly int $expectedRevision,
        public readonly int $position = 0,
        public readonly bool $isNavigable = true,
        public readonly bool $sitemapIncluded = true,
        public readonly ?string $sitemapPriority = null,
        public readonly ?SitemapChangeFrequency $sitemapChangeFrequency = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $expiresAt = null,
        #[LiteralTypeScriptType('Record<string, Record<string, unknown>>')]
        public readonly array $translations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/'],
            'kind' => ['required', Rule::enum(PageKind::class)],
            'resource' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'status' => ['required', Rule::enum(PageStatus::class)],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'position' => ['integer', 'min:0', 'max:2147483647'],
            'isNavigable' => ['boolean'],
            'sitemapIncluded' => ['boolean'],
            'sitemapPriority' => ['nullable', 'numeric', 'between:0,1'],
            'sitemapChangeFrequency' => ['nullable', Rule::enum(SitemapChangeFrequency::class)],
            'publishedAt' => ['nullable', 'date'],
            'expiresAt' => ['nullable', 'date', 'after:publishedAt'],
            'translations' => ['array', new SupportedLocaleMapRule],
            'translations.*' => ['array:title,navigationLabel,summary'],
            'translations.*.title' => ['required', 'string', 'max:255'],
            'translations.*.navigationLabel' => ['nullable', 'string', 'max:255'],
            'translations.*.summary' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
