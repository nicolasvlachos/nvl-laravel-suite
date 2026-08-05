<?php

declare(strict_types=1);

namespace Nvl\Content\Schema;

use InvalidArgumentException;
use JsonSerializable;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Immutable root schema with deterministic recursive traversal.
 */
final readonly class ContentSchema implements JsonSerializable
{
    /**
     * @param  list<ContentFieldDefinition>  $fields
     */
    public function __construct(public array $fields)
    {
        $keys = array_map(
            static fn (ContentFieldDefinition $field): string => $field->key,
            $fields,
        );

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Content schemas cannot contain duplicate root keys.');
        }

        $maximumFields = ContentConfiguration::positiveInteger(
            'content.validation.maximum_fields',
            250,
        );

        if ($this->fieldCount() > $maximumFields) {
            throw new InvalidArgumentException(
                "Content schema exceeds the configured {$maximumFields} field limit.",
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    public static function fromArray(array $schema): self
    {
        if (! array_is_list($schema)) {
            $unknown = array_diff(array_keys($schema), ['fields']);

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'Content schema has unknown properties: '.implode(', ', $unknown).'.',
                );
            }
        }

        $fieldDefinitions = array_is_list($schema)
            ? $schema
            : ($schema['fields'] ?? []);

        if (! is_array($fieldDefinitions)) {
            throw new InvalidArgumentException('Content schema fields must be an array.');
        }

        $fields = [];

        foreach (array_values($fieldDefinitions) as $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException('Every content schema field must be an object.');
            }

            $fields[] = ContentFieldDefinition::fromArray(
                ContentArrays::stringMap($field, 'content schema field'),
            );
        }

        return new self($fields);
    }

    public function get(string $key): ?ContentFieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function fieldCount(): int
    {
        $count = 0;

        $walk = static function (ContentFieldDefinition $field) use (&$count, &$walk): void {
            $count++;

            foreach ($field->fields as $child) {
                $walk($child);
            }

            if ($field->item !== null) {
                $walk($field->item);
            }
        };

        foreach ($this->fields as $field) {
            $walk($field);
        }

        return $count;
    }

    /**
     * Return safe root field-type hints for generic renderers.
     *
     * @return array<string, string>
     */
    public function fieldTypes(): array
    {
        $types = [];

        foreach ($this->fields as $field) {
            $types[$field->key] = $field->preset ?? $field->type;
        }

        return $types;
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'fields' => array_map(
                static fn (ContentFieldDefinition $field): array => $field->toArray(),
                $this->fields,
            ),
        ];
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
