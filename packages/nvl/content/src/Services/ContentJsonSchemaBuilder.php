<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use stdClass;

/**
 * Projects compiled Content schemas to interoperable JSON Schema Draft 2020-12 documents.
 */
final class ContentJsonSchemaBuilder
{
    public function __construct(private readonly ContentFieldPresetRegistry $presets) {}

    /**
     * Build one complete definition JSON Schema document.
     *
     * @return array<string, mixed>
     */
    public function definition(
        string $definition,
        int $version,
        ContentSchema $schema,
    ): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => "urn:nvl:content:{$definition}:v{$version}",
            ...$this->object($schema->fields),
        ];
    }

    /**
     * Build the JSON Schema fragment for one compiled field.
     *
     * @return array<string, mixed>
     */
    public function field(ContentFieldDefinition $field): array
    {
        $schema = match ($field->type) {
            'boolean' => ['type' => $this->type($field, 'boolean')],
            'integer' => $this->numeric($field, 'integer'),
            'number' => $this->numeric($field, 'number'),
            'object' => $this->objectField($field),
            'repeater', 'table' => [
                'type' => $this->type($field, 'array'),
                'items' => $this->row($field),
                ...$this->itemBounds($field),
            ],
            'list' => [
                'type' => $this->type($field, 'array'),
                'items' => $field->item !== null
                    ? $this->field($field->item)
                    : new stdClass,
                ...$this->itemBounds($field),
            ],
            'multi_select' => [
                'type' => $this->type($field, 'array'),
                'items' => [
                    'type' => 'string',
                    'enum' => $this->optionKeys($field),
                ],
                'uniqueItems' => true,
                ...$this->itemBounds($field),
            ],
            'media_collection' => [
                'type' => $this->type($field, 'array'),
                'items' => ['type' => 'string', 'format' => 'uuid'],
                'uniqueItems' => true,
                ...$this->itemBounds($field),
            ],
            'reference_list' => [
                'type' => $this->type($field, 'array'),
                'items' => ['type' => 'string', 'maxLength' => 191],
                'uniqueItems' => true,
                ...$this->itemBounds($field),
            ],
            'media' => ['type' => $this->type($field, 'string'), 'format' => 'uuid'],
            'reference' => ['type' => $this->type($field, 'string'), 'maxLength' => 191],
            'json' => $this->json($field),
            default => $this->string($field),
        };

        $schema['title'] = $field->label;
        $schema['x-content-type'] = $field->type;
        $schema['x-content-localized'] = $field->localized;

        if ($field->preset !== null) {
            $schema['x-content-preset'] = $field->preset;
        }

        if ($field->default !== null) {
            $schema['default'] = $field->default;
        }

        return $field->preset === null
            ? $schema
            : $this->presets->get($field->preset)->jsonSchema($schema, $field);
    }

    /**
     * @param  list<ContentFieldDefinition>  $fields
     * @return array<string, mixed>
     */
    private function object(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            $properties[$field->key] = $this->field($field);

            if ($field->required) {
                $required[] = $field->key;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new stdClass : $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectField(ContentFieldDefinition $field): array
    {
        return [
            ...$this->object($field->fields),
            'type' => $this->type($field, 'object'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ContentFieldDefinition $field): array
    {
        $schema = $this->object($field->fields);

        if ($field->type === 'repeater') {
            $properties = $schema['properties'];

            if (is_array($properties)) {
                $schema['properties'] = [
                    '_key' => [
                        'type' => 'string',
                        'pattern' => '^[A-Za-z0-9_-]{1,100}$',
                    ],
                    ...$properties,
                ];
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function string(ContentFieldDefinition $field): array
    {
        $schema = ['type' => $this->type($field, 'string')];
        $minimum = $field->setting('min_length');
        $defaultMaximum = $field->type === 'rich_text'
            ? ContentConfiguration::positiveInteger(
                'content.rich_text.maximum_input_length',
                250_000,
            )
            : ContentConfiguration::positiveInteger(
                'content.validation.maximum_string_length',
                100_000,
            );
        $maximum = $field->setting(
            'max_length',
            $defaultMaximum,
        );

        if (is_int($minimum)) {
            $schema['minLength'] = $minimum;
        }

        if (is_int($maximum)) {
            $schema['maxLength'] = $maximum;
        }

        $format = match ($field->type) {
            'date' => 'date',
            'date_time' => 'date-time',
            'email' => 'email',
            'url' => 'uri',
            'uri' => 'uri-reference',
            default => null,
        };

        if ($format !== null) {
            $schema['format'] = $format;
        }

        if ($field->type === 'select') {
            $schema['enum'] = $field->required
                ? $this->optionKeys($field)
                : [...$this->optionKeys($field), null];
        }

        $pattern = $field->setting('pattern');

        if (is_string($pattern)) {
            $schema['x-content-pattern'] = $pattern;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function numeric(ContentFieldDefinition $field, string $type): array
    {
        $schema = ['type' => $this->type($field, $type)];
        $minimum = $field->setting('min');
        $maximum = $field->setting('max');

        if (is_int($minimum) || is_float($minimum)) {
            $schema['minimum'] = $minimum;
        }

        if (is_int($maximum) || is_float($maximum)) {
            $schema['maximum'] = $maximum;
        }

        return $schema;
    }

    /**
     * Return a final-value JSON Schema type with nullability matching field semantics.
     *
     * @return string|list<string>
     */
    private function type(ContentFieldDefinition $field, string $type): string|array
    {
        return $field->required ? $type : [$type, 'null'];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemBounds(ContentFieldDefinition $field): array
    {
        $bounds = [];
        $minimum = $field->setting('min_items');
        $maximum = $field->setting(
            'max_items',
            ContentConfiguration::positiveInteger(
                $field->type === 'media_collection'
                    ? 'content.media.maximum_per_field'
                    : 'content.validation.maximum_items',
                $field->type === 'media_collection' ? 50 : 500,
            ),
        );

        if (is_int($minimum)) {
            $bounds['minItems'] = $minimum;
        }

        if (is_int($maximum)) {
            $bounds['maxItems'] = $maximum;
        }

        return $bounds;
    }

    /**
     * @return list<string>
     */
    private function optionKeys(ContentFieldDefinition $field): array
    {
        $options = $field->setting('options', []);

        if (! is_array($options)) {
            return [];
        }

        return array_is_list($options)
            ? array_values(array_filter($options, 'is_string'))
            : array_values(array_filter(array_keys($options), 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ContentFieldDefinition $field): array
    {
        $schema = $field->setting('schema');

        if (! is_array($schema)) {
            return [];
        }

        $schema = ContentArrays::stringMap(
            $schema,
            "content JSON field {$field->key} schema",
        );

        return $field->required
            ? $schema
            : [
                'anyOf' => [
                    $schema,
                    ['type' => 'null'],
                ],
            ];
    }
}
