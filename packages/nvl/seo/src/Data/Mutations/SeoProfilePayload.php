<?php

declare(strict_types=1);

namespace Nvl\Seo\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete patch/replace contract for an owner's scoped SEO profile.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class SeoProfilePayload extends Data
{
    use DataTransform;

    /**
     * Return every accepted camel-case profile mutation field.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'isIndexable',
            'isFollowable',
            'maxSnippet',
            'maxImagePreview',
            'maxVideoPreview',
            'sitemapIncluded',
            'sitemapPriority',
            'sitemapChangeFrequency',
            'metadata',
            'translations',
            'expectedRevision',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>|Optional  $translations
     * @param  array<string, mixed>|Optional|null  $metadata
     */
    public function __construct(
        #[TypeScriptOptional]
        public readonly bool|Optional $isIndexable = new Optional,
        #[TypeScriptOptional]
        public readonly bool|Optional $isFollowable = new Optional,
        #[TypeScriptOptional]
        public readonly int|Optional|null $maxSnippet = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $maxImagePreview = new Optional,
        #[TypeScriptOptional]
        public readonly int|Optional|null $maxVideoPreview = new Optional,
        #[TypeScriptOptional]
        public readonly bool|Optional $sitemapIncluded = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $sitemapPriority = new Optional,
        #[TypeScriptOptional]
        public readonly SitemapChangeFrequency|Optional|null $sitemapChangeFrequency = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $metadata = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, Nvl.Seo.Data.Mutations.SeoTranslationPayload>')]
        public readonly array|Optional $translations = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $expectedRevision = new Optional,
    ) {}

    /**
     * Return validation rules for the complete SEO mutation boundary.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'isIndexable' => ['sometimes', 'boolean'],
            'isFollowable' => ['sometimes', 'boolean'],
            'maxSnippet' => ['sometimes', 'nullable', 'integer', 'min:-1'],
            'maxImagePreview' => ['sometimes', 'nullable', Rule::in(['none', 'standard', 'large'])],
            'maxVideoPreview' => ['sometimes', 'nullable', 'integer', 'min:-1'],
            'sitemapIncluded' => ['sometimes', 'boolean'],
            'sitemapPriority' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'sitemapChangeFrequency' => [
                'sometimes',
                'nullable',
                Rule::enum(SitemapChangeFrequency::class),
            ],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'translations' => ['sometimes', 'array', new SupportedLocaleMapRule],
            'translations.*' => [
                'array:'.implode(',', SeoTranslationPayload::fields()),
            ],
            'expectedRevision' => ['sometimes', 'nullable', 'integer', 'min:0'],
            ...SeoTranslationPayload::scopedRules('translations.*.'),
        ];
    }
}
