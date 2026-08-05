<?php

declare(strict_types=1);

namespace Nvl\Content\Schema;

use InvalidArgumentException;
use Nvl\Content\Support\ContentArrays;

/**
 * Immutable recursive field definition shared by source definitions and model casts.
 */
final readonly class ContentFieldDefinition
{
    /** @var list<string> */
    private const array ALLOWED_PROPERTIES = [
        'key',
        'type',
        'preset',
        'label',
        'required',
        'localized',
        'default',
        'fields',
        'item',
        'settings',
    ];

    /**
     * @param  list<ContentFieldDefinition>  $fields
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public bool $required = false,
        public bool $localized = false,
        public mixed $default = null,
        public array $fields = [],
        public ?self $item = null,
        public array $settings = [],
        public ?string $preset = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/', $key) !== 1) {
            throw new InvalidArgumentException("Content field key [{$key}] is invalid.");
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $type) !== 1) {
            throw new InvalidArgumentException("Content field type [{$type}] is invalid.");
        }

        if ($preset !== null
            && preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $preset) !== 1) {
            throw new InvalidArgumentException("Content field preset [{$preset}] is invalid.");
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException("Content field [{$key}] requires a label.");
        }

        $keys = array_map(
            static fn (self $field): string => $field->key,
            $fields,
        );

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException("Content field [{$key}] has duplicate child keys.");
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $unknown = array_diff(array_keys($definition), self::ALLOWED_PROPERTIES);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Content field has unknown properties: '.implode(', ', $unknown).'.',
            );
        }

        $key = $definition['key'] ?? null;
        $type = $definition['type'] ?? null;
        $preset = $definition['preset'] ?? null;
        $label = $definition['label'] ?? $key;

        if (! is_string($key)
            || ! is_string($type)
            || ! is_string($label)
            || $preset !== null && ! is_string($preset)) {
            throw new InvalidArgumentException('Content fields require string key, type, and label values.');
        }

        $required = $definition['required'] ?? false;
        $localized = $definition['localized'] ?? false;

        if (! is_bool($required) || ! is_bool($localized)) {
            throw new InvalidArgumentException(
                "Content field [{$key}] required and localized properties must be booleans.",
            );
        }

        $fields = $definition['fields'] ?? [];

        if (! is_array($fields)) {
            throw new InvalidArgumentException("Content field [{$key}] children must be an array.");
        }

        $children = [];

        foreach (array_values($fields) as $child) {
            if (! is_array($child)) {
                throw new InvalidArgumentException("Content field [{$key}] has an invalid child.");
            }

            $children[] = self::fromArray(
                ContentArrays::stringMap($child, "content field {$key} child"),
            );
        }

        $itemDefinition = $definition['item'] ?? null;
        $item = null;

        if ($itemDefinition !== null) {
            if (! is_array($itemDefinition)) {
                throw new InvalidArgumentException("Content field [{$key}] item must be an array.");
            }

            $normalizedItem = ContentArrays::stringMap(
                $itemDefinition,
                "content field {$key} item",
            );
            $normalizedItem = [
                'key' => 'item',
                'label' => 'Item',
                ...$normalizedItem,
            ];
            $item = self::fromArray($normalizedItem);
        }

        $settings = $definition['settings'] ?? [];

        if (! is_array($settings)) {
            throw new InvalidArgumentException("Content field [{$key}] settings must be an object.");
        }

        return new self(
            key: $key,
            type: $type,
            preset: $preset,
            label: $label,
            required: $required,
            localized: $localized,
            default: $definition['default'] ?? null,
            fields: $children,
            item: $item,
            settings: ContentArrays::stringMap($settings, "content field {$key} settings"),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = [
            'key' => $this->key,
            'type' => $this->type,
            'preset' => $this->preset,
            'label' => $this->label,
            'required' => $this->required,
            'localized' => $this->localized,
            'default' => $this->default,
            'settings' => $this->settings,
        ];

        if ($this->fields !== []) {
            $definition['fields'] = array_map(
                static fn (self $field): array => $field->toArray(),
                $this->fields,
            );
        }

        if ($this->item !== null) {
            $definition['item'] = $this->item->toArray();
        }

        return $definition;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
