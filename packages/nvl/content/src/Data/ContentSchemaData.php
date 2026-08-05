<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Content\Schema\ContentSchema;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Generated-client contract for a compiled Content schema.
 */
#[TypeScript]
final class ContentSchemaData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentFieldDefinitionData>  $fields
     */
    public function __construct(
        #[DataCollectionOf(ContentFieldDefinitionData::class)]
        public readonly array $fields,
    ) {}

    /**
     * Create a generated-client contract from one compiled domain schema.
     */
    public static function fromSchema(ContentSchema $schema): self
    {
        return new self(array_map(
            ContentFieldDefinitionData::fromDefinition(...),
            $schema->fields,
        ));
    }

    /**
     * Restore the immutable domain schema represented by this transport contract.
     */
    public function toSchema(): ContentSchema
    {
        return new ContentSchema(array_map(
            static fn (ContentFieldDefinitionData $field) => $field->toDefinition(),
            $this->fields,
        ));
    }
}
