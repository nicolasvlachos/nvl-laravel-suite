<?php

declare(strict_types=1);

namespace Nvl\Content\Http;

use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Data\ContentEditorData;
use Nvl\Content\Data\ContentFieldPresetData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Data\RenderedContentBlockData;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Content\Support\ContentArrays;
use stdClass;

/**
 * Preserves JSON object/list fidelity at the optional HTTP boundary.
 */
final class ContentResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function preset(ContentFieldPresetData $preset): array
    {
        $data = $preset->toArray();
        $data['field'] = self::schemaField($preset->field->toArray());
        $data['jsonSchema'] = self::object($preset->jsonSchema);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(ContentDefinitionData $definition): array
    {
        $data = $definition->toArray();
        $data['defaults'] = self::object($definition->defaults);
        $data['schema'] = self::schema($definition->schema->toArray());
        $data['jsonSchema'] = self::object($definition->jsonSchema);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function editor(ContentEditorData $editor): array
    {
        return [
            'ownerType' => $editor->ownerType,
            'ownerId' => $editor->ownerId,
            'group' => $editor->group,
            'placementLimit' => $editor->placementLimit,
            'definitions' => array_map(self::definition(...), $editor->definitions),
            'presets' => array_map(self::preset(...), $editor->presets),
            'groups' => $editor->groups,
            'placements' => array_map(self::placement(...), $editor->placements),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function block(ContentBlockData $block): array
    {
        $data = $block->toArray();
        $data['values'] = self::object($block->values);
        $data['metadata'] = self::object($block->metadata);
        $translations = [];

        foreach ($block->translations as $locale => $values) {
            $translations[$locale] = self::object($values);
        }

        $data['translations'] = self::object($translations);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function placement(ContentPlacementData $placement): array
    {
        $data = $placement->toArray();
        $data['overrides'] = self::object($placement->overrides);

        if ($placement->block === null) {
            unset($data['block']);
        } else {
            $data['block'] = self::block($placement->block);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function composition(RenderedContentCompositionData $composition): array
    {
        $data = $composition->toArray();
        $data['blocks'] = array_map(
            self::renderedBlock(...),
            $composition->blocks,
        );
        $regions = [];

        foreach ($composition->regions as $region => $blocks) {
            $regions[$region] = array_map(self::renderedBlock(...), $blocks);
        }

        $data['regions'] = self::object($regions);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function renderedBlock(RenderedContentBlockData $block): array
    {
        $data = $block->toArray();
        $data['values'] = self::object($block->values);
        $data['fieldTypes'] = self::object($block->fieldTypes);
        $data['children'] = array_map(self::renderedBlock(...), $block->children);

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return array{fields: list<array<string, mixed>>}
     */
    private static function schema(array $schema): array
    {
        $fields = $schema['fields'] ?? $schema;

        if (! is_array($fields)) {
            return ['fields' => []];
        }

        $normalized = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $normalized[] = self::schemaField(
                ContentArrays::stringMap($field, 'content response schema field'),
            );
        }

        return ['fields' => $normalized];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function schemaField(array $field): array
    {
        $settings = $field['settings'] ?? [];
        $field['settings'] = self::object(is_array($settings) ? $settings : []);

        if (isset($field['fields']) && is_array($field['fields'])) {
            $children = [];

            foreach ($field['fields'] as $child) {
                if (is_array($child)) {
                    $children[] = self::schemaField(
                        ContentArrays::stringMap($child, 'content response child schema field'),
                    );
                }
            }

            $field['fields'] = $children;
        }

        if (isset($field['item']) && is_array($field['item'])) {
            $field['item'] = self::schemaField(
                ContentArrays::stringMap($field['item'], 'content response item schema field'),
            );
        }

        return $field;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|stdClass
     */
    private static function object(array $value): array|stdClass
    {
        return $value === [] ? new stdClass : $value;
    }
}
