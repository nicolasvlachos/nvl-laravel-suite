<?php

declare(strict_types=1);

namespace Nvl\Content\Validation;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentFieldDefinitionValidator;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Validates compiled Content schemas without depending on request-scoped state.
 */
final readonly class ContentSchemaValidator
{
    public function __construct(
        private ContentFieldTypeRegistry $fieldTypes,
        private ContentFieldPresetRegistry $presets,
        private ContentFieldSettingsValidator $settings,
    ) {}

    public function validate(ContentSchema $schema): void
    {
        foreach ($schema->fields as $field) {
            $this->validateField($field, 1);
        }
    }

    private function validateField(ContentFieldDefinition $field, int $depth): void
    {
        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_depth',
            12,
        );

        if ($depth > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] exceeds the {$maximum} level depth limit.",
            );
        }

        if ($field->preset !== null) {
            $this->presets->get($field->preset);
        }

        $adapter = $this->fieldTypes->get($field->type);

        if ($adapter instanceof ContentFieldDefinitionValidator) {
            $adapter->validateDefinition($field);
        }

        $this->settings->validate($field);
        $this->assertStructure($field);

        foreach ($field->fields as $child) {
            $this->validateField($child, $depth + 1);
        }

        if ($field->item !== null) {
            $this->validateField($field->item, $depth + 1);
        }
    }

    private function assertStructure(ContentFieldDefinition $field): void
    {
        if ($field->type === 'list' && $field->item === null) {
            throw new InvalidArgumentException(
                "Content list field [{$field->key}] requires an item definition.",
            );
        }

        if ($field->type !== 'list' && $field->item !== null) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] cannot declare an item definition.",
            );
        }

        if (in_array($field->type, ['object', 'repeater', 'table'], true)
            && $field->fields === []) {
            throw new InvalidArgumentException(
                "Structured content field [{$field->key}] requires child fields.",
            );
        }

        if (! in_array($field->type, ['object', 'repeater', 'table'], true)
            && $field->fields !== []) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] cannot declare child fields.",
            );
        }

        if ($field->type === 'json' && ! is_array($field->setting('schema'))) {
            throw new InvalidArgumentException(
                "JSON content field [{$field->key}] requires a schema setting.",
            );
        }
    }
}
