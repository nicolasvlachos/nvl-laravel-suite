<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Nvl\Content\Models\ContentBlockTranslation;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentArrays;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Partitions and deeply merges mixed localized/non-localized structured content values.
 */
final readonly class ContentLocalizedValues
{
    public function __construct(private ContentLocalePolicy $locales) {}

    /**
     * Determine whether a field contributes values to one localization partition.
     */
    public function includes(
        ContentFieldDefinition $field,
        bool $localized,
        bool $ancestorLocalized = false,
    ): bool {
        $effectiveLocalized = $ancestorLocalized || $field->localized;

        if ($effectiveLocalized) {
            return $localized;
        }

        if ($field->fields !== []) {
            if (! $localized) {
                return true;
            }

            foreach ($field->fields as $child) {
                if ($this->includes($child, true)) {
                    return true;
                }
            }

            return false;
        }

        if ($field->item !== null) {
            return ! $localized || $this->includes($field->item, true);
        }

        return ! $localized;
    }

    /**
     * Resolve a locale with deterministic deep field-level fallback.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, mixed>
     */
    public function resolve(
        ContentSchema $schema,
        array $translations,
        string $locale,
    ): array {
        if ($translations === []) {
            return [];
        }

        $definition = new RelatedTranslationDefinition(
            translationModel: ContentBlockTranslation::class,
            fields: ['values'],
            locales: $this->locales->available(),
        );
        $chain = $definition->localeChain($locale, array_keys($translations));
        $resolved = [];

        foreach (array_reverse($chain) as $candidate) {
            if (! isset($translations[$candidate])) {
                continue;
            }

            $resolved = $this->overlaySchema(
                $schema,
                $resolved,
                $translations[$candidate],
                $definition->shouldFallbackOnNull(),
            );
        }

        return $resolved;
    }

    /**
     * Merge one normalized localized projection over normalized base values.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $localized
     * @return array<string, mixed>
     */
    public function overlay(ContentSchema $schema, array $base, array $localized): array
    {
        return $this->overlaySchema($schema, $base, $localized, false);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $localized
     * @return array<string, mixed>
     */
    private function overlaySchema(
        ContentSchema $schema,
        array $base,
        array $localized,
        bool $fallbackOnNull,
    ): array {
        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->key, $localized)) {
                continue;
            }

            $base[$field->key] = $this->overlayField(
                $field,
                $base[$field->key] ?? null,
                $localized[$field->key],
                false,
                $fallbackOnNull,
            );
        }

        return $base;
    }

    private function overlayField(
        ContentFieldDefinition $field,
        mixed $base,
        mixed $localized,
        bool $ancestorLocalized,
        bool $fallbackOnNull,
    ): mixed {
        $effectiveLocalized = $ancestorLocalized || $field->localized;

        if ($localized === null && $fallbackOnNull && $base !== null) {
            return $base;
        }

        if ($effectiveLocalized || $localized === null) {
            return $localized;
        }

        if ($field->type === 'object' && is_array($localized)) {
            return $this->overlayObject(
                $field,
                $base,
                $localized,
                $fallbackOnNull,
            );
        }

        if ($field->type === 'repeater' && is_array($localized)) {
            return $this->overlayRepeater(
                $field,
                $base,
                $localized,
                $fallbackOnNull,
            );
        }

        if (in_array($field->type, ['table', 'list'], true) && is_array($localized)) {
            return $this->overlayIndexed(
                $field,
                $base,
                $localized,
                $fallbackOnNull,
            );
        }

        return $localized;
    }

    /**
     * @param  array<array-key, mixed>  $localized
     * @return array<string, mixed>
     */
    private function overlayObject(
        ContentFieldDefinition $field,
        mixed $base,
        array $localized,
        bool $fallbackOnNull,
    ): array {
        $merged = is_array($base) && ! array_is_list($base)
            ? ContentArrays::stringMap($base, "localized content {$field->key} base")
            : [];
        $patch = ContentArrays::stringMap(
            $localized,
            "localized content {$field->key}",
        );

        foreach ($field->fields as $child) {
            if (array_key_exists($child->key, $patch)) {
                $merged[$child->key] = $this->overlayField(
                    $child,
                    $merged[$child->key] ?? null,
                    $patch[$child->key],
                    false,
                    $fallbackOnNull,
                );
            }
        }

        return $merged;
    }

    /**
     * @param  array<array-key, mixed>  $localized
     * @return list<mixed>
     */
    private function overlayRepeater(
        ContentFieldDefinition $field,
        mixed $base,
        array $localized,
        bool $fallbackOnNull,
    ): array {
        if (! array_is_list($localized)) {
            return is_array($base) && array_is_list($base) ? $base : [];
        }

        $rows = is_array($base) && array_is_list($base) ? $base : [];
        $positions = [];

        foreach ($rows as $index => $row) {
            if (is_array($row) && is_string($row['_key'] ?? null)) {
                $positions[$row['_key']] = $index;
            }
        }

        foreach ($localized as $index => $localizedRow) {
            if (! is_array($localizedRow)) {
                continue;
            }

            $key = is_string($localizedRow['_key'] ?? null)
                ? $localizedRow['_key']
                : null;
            $position = $key !== null ? ($positions[$key] ?? null) : $index;
            $baseRow = is_int($position) && is_array($rows[$position] ?? null)
                ? $rows[$position]
                : [];
            $merged = $this->overlayObject(
                $field,
                $baseRow,
                $localizedRow,
                $fallbackOnNull,
            );

            if ($key !== null) {
                $merged = ['_key' => $key, ...$merged];
            }

            if (is_int($position)) {
                $rows[$position] = $merged;
            } else {
                $rows[] = $merged;
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<array-key, mixed>  $localized
     * @return list<mixed>
     */
    private function overlayIndexed(
        ContentFieldDefinition $field,
        mixed $base,
        array $localized,
        bool $fallbackOnNull,
    ): array {
        if (! array_is_list($localized)) {
            return is_array($base) && array_is_list($base) ? $base : [];
        }

        $items = is_array($base) && array_is_list($base) ? $base : [];

        foreach ($localized as $index => $localizedItem) {
            $baseItem = $items[$index] ?? null;

            if ($field->type === 'list' && $field->item !== null) {
                $items[$index] = $this->overlayField(
                    $field->item,
                    $baseItem,
                    $localizedItem,
                    false,
                    $fallbackOnNull,
                );
            } elseif (is_array($localizedItem)) {
                $items[$index] = $this->overlayObject(
                    $field,
                    $baseItem,
                    $localizedItem,
                    $fallbackOnNull,
                );
            } else {
                $items[$index] = $localizedItem;
            }
        }

        return array_values($items);
    }
}
