<?php

declare(strict_types=1);

namespace Nvl\Content\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Content\Enums\ContentMutationMode;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentObjectRule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Revision-safe patch or explicit replacement contract for editable content.
 */
#[TypeScript]
final class UpdateContentBlockData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $expectedRevision,
        #[TypeScriptOptional]
        public readonly ContentMutationMode $mode = ContentMutationMode::Patch,
        #[TypeScriptOptional]
        public readonly ?ContentVisibility $visibility = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $values = [],
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, Record<string, unknown>>')]
        public readonly array $translations = [],
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', Rule::enum(ContentMutationMode::class)],
            'visibility' => ['nullable', Rule::enum(ContentVisibility::class)],
            'values' => ['array', new ContentObjectRule],
            'translations' => [
                'array',
                new ContentObjectRule,
                new SupportedLocaleMapRule(self::contentLocales()),
            ],
            'translations.*' => ['array', new ContentObjectRule],
            'metadata' => ['array', new ContentObjectRule],
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function contentLocales(): ?array
    {
        $configured = ContentConfiguration::stringList('content.locales.available');

        return $configured === [] ? null : $configured;
    }
}
