<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Support\Str;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentArrays;

/**
 * Extracts media references with stable field paths from normalized values.
 */
final class ContentMediaReferences
{
    /**
     * @param  array<string, mixed>  $values
     * @return list<array{id: string, path: string, locale: string|null, order: int}>
     */
    public function extract(
        ContentSchema $schema,
        array $values,
        ?string $locale,
    ): array {
        $references = [];

        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $this->walk(
                $field,
                $values[$field->key],
                $field->key,
                $locale,
                $references,
            );
        }

        return $references;
    }

    /**
     * @param  list<array{id: string, path: string, locale: string|null, order: int}>  $references
     */
    private function walk(
        ContentFieldDefinition $field,
        mixed $value,
        string $path,
        ?string $locale,
        array &$references,
    ): void {
        if ($field->type === 'media' && is_string($value) && Str::isUuid($value)) {
            $references[] = ['id' => $value, 'path' => $path, 'locale' => $locale, 'order' => 0];

            return;
        }

        if ($field->type === 'media_collection' && is_array($value)) {
            foreach ($value as $order => $id) {
                if (is_int($order) && is_string($id) && Str::isUuid($id)) {
                    $references[] = compact('id', 'path', 'locale', 'order');
                }
            }

            return;
        }

        if ($field->type === 'object' && is_array($value)) {
            $this->walkChildren(
                $field,
                ContentArrays::stringMap($value, "content media field {$path}"),
                $path,
                $locale,
                $references,
            );

            return;
        }

        if (in_array($field->type, ['repeater', 'table'], true) && is_array($value)) {
            foreach ($value as $index => $row) {
                if (is_int($index) && is_array($row)) {
                    $this->walkChildren(
                        $field,
                        ContentArrays::stringMap($row, "content media row {$path}.{$index}"),
                        "{$path}.{$index}",
                        $locale,
                        $references,
                    );
                }
            }

            return;
        }

        if ($field->type === 'list' && $field->item !== null && is_array($value)) {
            foreach ($value as $index => $item) {
                if (is_int($index)) {
                    $this->walk(
                        $field->item,
                        $item,
                        "{$path}.{$index}",
                        $locale,
                        $references,
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array{id: string, path: string, locale: string|null, order: int}>  $references
     */
    private function walkChildren(
        ContentFieldDefinition $parent,
        array $values,
        string $path,
        ?string $locale,
        array &$references,
    ): void {
        foreach ($parent->fields as $field) {
            if (array_key_exists($field->key, $values)) {
                $this->walk(
                    $field,
                    $values[$field->key],
                    "{$path}.{$field->key}",
                    $locale,
                    $references,
                );
            }
        }
    }
}
