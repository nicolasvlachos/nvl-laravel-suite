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
 * Validated contract for creating a structural page and its initial locale rows.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CreatePageData extends Data
{
    use DataTransform;

    /**
     * Create one validated page creation payload.
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function __construct(
        public readonly string $key,
        public readonly string $slug,
        public readonly ?string $parentId = null,
        public readonly string $site = 'default',
        public readonly PageKind $kind = PageKind::Static,
        public readonly ?string $resource = null,
        public readonly PageStatus $status = PageStatus::Draft,
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
            'key' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/'],
            'parentId' => ['nullable', 'uuid'],
            'site' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'kind' => ['sometimes', Rule::enum(PageKind::class)],
            'resource' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'status' => ['sometimes', Rule::enum(PageStatus::class)],
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
