<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Generated-client contract for one recursive Content schema field.
 */
#[TypeScript]
final class ContentFieldDefinitionData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentFieldDefinitionData>  $fields
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required,
        public readonly bool $localized,
        #[LiteralTypeScriptType('unknown')]
        public readonly mixed $default,
        #[TypeScriptOptional]
        #[DataCollectionOf(self::class)]
        public readonly array $fields = [],
        #[TypeScriptOptional]
        public readonly ?self $item = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $settings = [],
        #[TypeScriptOptional]
        public readonly ?string $preset = null,
    ) {}

    /**
     * Create the transport contract for one compiled domain field.
     */
    public static function fromDefinition(ContentFieldDefinition $field): self
    {
        return new self(
            key: $field->key,
            type: $field->type,
            label: $field->label,
            required: $field->required,
            localized: $field->localized,
            default: $field->default,
            fields: array_map(
                self::fromDefinition(...),
                $field->fields,
            ),
            item: $field->item === null
                ? null
                : self::fromDefinition($field->item),
            settings: $field->settings,
            preset: $field->preset,
        );
    }

    /**
     * Restore the immutable domain field represented by this transport contract.
     */
    public function toDefinition(): ContentFieldDefinition
    {
        return new ContentFieldDefinition(
            key: $this->key,
            type: $this->type,
            label: $this->label,
            required: $this->required,
            localized: $this->localized,
            default: $this->default,
            fields: array_map(
                static fn (self $field): ContentFieldDefinition => $field->toDefinition(),
                $this->fields,
            ),
            item: $this->item?->toDefinition(),
            settings: $this->settings,
            preset: $this->preset,
        );
    }
}
