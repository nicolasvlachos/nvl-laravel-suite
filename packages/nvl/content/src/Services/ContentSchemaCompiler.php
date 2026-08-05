<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentFieldPreset;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Expands semantic field presets into deterministic recursive Content schemas.
 */
final readonly class ContentSchemaCompiler
{
    public function __construct(private ContentFieldPresetRegistry $presets) {}

    /**
     * Compile a raw source schema to its complete persisted representation.
     *
     * @param  array<array-key, mixed>  $schema
     */
    public function compile(array $schema): ContentSchema
    {
        if (! array_is_list($schema)) {
            $unknown = array_diff(array_keys($schema), ['fields']);

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'Content schema has unknown properties: '.implode(', ', $unknown).'.',
                );
            }
        }

        $fields = array_is_list($schema) ? $schema : ($schema['fields'] ?? null);

        if (! is_array($fields)) {
            throw new InvalidArgumentException('Content schema fields must be an array.');
        }

        $compiled = [];

        foreach (array_values($fields) as $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException('Every content schema field must be an object.');
            }

            $compiled[] = $this->compileField(
                ContentArrays::stringMap($field, 'content schema field'),
                [],
                1,
            );
        }

        return ContentSchema::fromArray(['fields' => $compiled]);
    }

    /**
     * Compile one registered preset to an editor-ready field definition.
     */
    public function compilePreset(ContentFieldPreset $preset): ContentFieldDefinition
    {
        $field = $this->compileField(
            [
                'key' => 'value',
                'label' => $preset->name(),
                'preset' => $preset->alias(),
            ],
            [],
            1,
        );

        return ContentFieldDefinition::fromArray($field);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $presetStack
     * @return array<string, mixed>
     */
    private function compileField(array $field, array $presetStack, int $depth): array
    {
        $maximumDepth = ContentConfiguration::positiveInteger(
            'content.validation.maximum_depth',
            12,
        );

        if ($depth > $maximumDepth) {
            throw new InvalidArgumentException(
                "Content schema preset expansion exceeds the {$maximumDepth} level depth limit.",
            );
        }

        $presetAlias = $field['preset'] ?? null;

        if ($presetAlias !== null) {
            if (! is_string($presetAlias)
                || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $presetAlias) !== 1) {
                throw new InvalidArgumentException('Content field preset must be a valid alias.');
            }

            if (in_array($presetAlias, $presetStack, true)) {
                throw new InvalidArgumentException(
                    'Content field presets contain a cycle: '.
                    implode(' -> ', [...$presetStack, $presetAlias]).'.',
                );
            }

            $preset = $this->presets->get($presetAlias);
            $field = $this->mergePreset($preset, $field);
            $presetStack = [...$presetStack, $presetAlias];
        }

        $children = $field['fields'] ?? null;

        if (is_array($children)) {
            $compiledChildren = [];

            foreach (array_values($children) as $child) {
                if (! is_array($child)) {
                    throw new InvalidArgumentException('Content preset child fields must be objects.');
                }

                $compiledChildren[] = $this->compileField(
                    ContentArrays::stringMap($child, 'content preset child field'),
                    $presetStack,
                    $depth + 1,
                );
            }

            $field['fields'] = $compiledChildren;
        }

        $item = $field['item'] ?? null;

        if (is_array($item)) {
            $field['item'] = $this->compileField(
                ContentArrays::stringMap($item, 'content preset item field'),
                $presetStack,
                $depth + 1,
            );
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function mergePreset(ContentFieldPreset $preset, array $field): array
    {
        foreach (['type', 'fields', 'item'] as $structuralProperty) {
            if (array_key_exists($structuralProperty, $field)) {
                throw new InvalidArgumentException(
                    "Content field preset [{$preset->alias()}] cannot override structural property [{$structuralProperty}].",
                );
            }
        }

        $definition = $preset->definition();
        $presetSettings = $definition['settings'] ?? [];
        $fieldSettings = $field['settings'] ?? [];

        if (! is_array($presetSettings) || ! is_array($fieldSettings)) {
            throw new InvalidArgumentException(
                "Content field preset [{$preset->alias()}] settings must be objects.",
            );
        }

        $merged = [
            ...$definition,
            ...$field,
            'preset' => $preset->alias(),
            'settings' => [
                ...ContentArrays::stringMap(
                    $presetSettings,
                    "content field preset {$preset->alias()} settings",
                ),
                ...ContentArrays::stringMap(
                    $fieldSettings,
                    "content field {$preset->alias()} settings",
                ),
            ],
        ];

        if (! isset($merged['label']) && is_string($merged['key'] ?? null)) {
            $merged['label'] = $preset->name();
        }

        return $merged;
    }
}
