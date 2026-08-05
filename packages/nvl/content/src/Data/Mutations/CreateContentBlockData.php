<?php

declare(strict_types=1);

namespace Nvl\Content\Data\Mutations;

use Illuminate\Validation\Rule;
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
 * Validated contract for creating a reusable draft content block.
 */
#[TypeScript]
final class CreateContentBlockData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $definition,
        public readonly string $key,
        #[TypeScriptOptional]
        public readonly string $scope = 'global',
        #[TypeScriptOptional]
        public readonly string $scopeKey = '*',
        #[TypeScriptOptional]
        public readonly ContentVisibility $visibility = ContentVisibility::Public,
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
            'definition' => ['required', 'string', 'max:191', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'scope' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'scopeKey' => ['sometimes', 'string', 'max:191'],
            'visibility' => ['sometimes', Rule::enum(ContentVisibility::class)],
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
