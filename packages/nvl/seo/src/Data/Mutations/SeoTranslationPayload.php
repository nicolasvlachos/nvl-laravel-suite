<?php

declare(strict_types=1);

namespace Nvl\Seo\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Seo\Enums\TwitterCard;
use Nvl\Seo\Rules\CanonicalUrl;
use Nvl\Seo\Rules\ValidStructuredData;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Patch contract for SEO metadata in one locale.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class SeoTranslationPayload extends Data
{
    use DataTransform;

    /**
     * Return every accepted camel-case translation mutation field.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'path',
            'title',
            'description',
            'canonicalUrl',
            'imageUrl',
            'imageReference',
            'imageAlt',
            'openGraphTitle',
            'openGraphDescription',
            'twitterTitle',
            'twitterDescription',
            'twitterCard',
            'structuredData',
            'metadata',
        ];
    }

    /**
     * @param  array<array-key, mixed>|Optional|null  $structuredData
     * @param  array<string, mixed>|Optional|null  $metadata
     */
    public function __construct(
        #[TypeScriptOptional]
        public readonly string|Optional|null $path = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $title = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $description = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $canonicalUrl = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $imageUrl = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $imageReference = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $imageAlt = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $openGraphTitle = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $openGraphDescription = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $twitterTitle = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $twitterDescription = new Optional,
        #[TypeScriptOptional]
        public readonly TwitterCard|Optional|null $twitterCard = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | Array<Record<string, unknown>> | null')]
        public readonly array|Optional|null $structuredData = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $metadata = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'canonicalUrl' => ['sometimes', 'nullable', 'string', 'max:2048', new CanonicalUrl],
            'imageUrl' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'imageReference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imageAlt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'openGraphTitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'openGraphDescription' => ['sometimes', 'nullable', 'string', 'max:500'],
            'twitterTitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'twitterDescription' => ['sometimes', 'nullable', 'string', 'max:500'],
            'twitterCard' => ['sometimes', 'nullable', Rule::enum(TwitterCard::class)],
            'structuredData' => ['sometimes', 'nullable', 'array', new ValidStructuredData],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
