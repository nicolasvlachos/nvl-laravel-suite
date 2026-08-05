<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplatePayloadValidator;
use Nvl\Templates\Exceptions\TemplateResolutionException;

/**
 * Validates a deliberately bounded, portable subset of JSON Schema.
 *
 * Consumer applications may bind a full schema implementation behind the
 * contract without changing template Actions.
 */
final class ConfiguredTemplatePayloadValidator implements TemplatePayloadValidator
{
    private const array TYPES = [
        'array',
        'boolean',
        'integer',
        'null',
        'number',
        'object',
        'string',
    ];

    /**
     * @param  array<string, mixed>  $schema
     */
    public function validateSchema(array $schema): void
    {
        if ($schema === []) {
            return;
        }

        $this->assertSchemaNode($this->normalizeRoot($schema), '$', 0);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $schema, array $payload): void
    {
        if ($schema === []) {
            return;
        }

        $normalized = $this->normalizeRoot($schema);
        $this->assertSchemaNode($normalized, '$', 0);
        $this->validateValue($payload, $normalized, '$', 0);
    }

    /**
     * Accept the concise `property => rules` form as well as object schemas.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeRoot(array $schema): array
    {
        if (array_key_exists('type', $schema) || array_key_exists('properties', $schema)) {
            return $schema;
        }

        $required = [];

        foreach ($schema as $property => $rules) {
            if (! is_array($rules)) {
                continue;
            }

            if (($rules['required'] ?? false) === true) {
                $required[] = $property;
                unset($rules['required']);
                $schema[$property] = $rules;
            }
        }

        return [
            'type' => 'object',
            'properties' => $schema,
            'required' => $required,
            'additionalProperties' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertSchemaNode(array $schema, string $path, int $depth): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Template payload schema nesting exceeds 16 levels.');
        }

        $type = $schema['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}] requires a supported type.",
            );
        }

        $unknown = array_diff(array_keys($schema), $this->allowedKeys($type));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}] contains unsupported keyword [".
                (string) reset($unknown).'].',
            );
        }

        $nullable = $schema['nullable'] ?? false;

        if (! is_bool($nullable)) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}.nullable] must be boolean.",
            );
        }

        if (isset($schema['enum'])
            && (! is_array($schema['enum']) || $schema['enum'] === [])) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}.enum] must be a non-empty array.",
            );
        }

        if (is_array($schema['enum'] ?? null)) {
            foreach ($schema['enum'] as $enumValue) {
                if ($enumValue === null && $nullable) {
                    continue;
                }

                if (! $this->matchesType($enumValue, $type)) {
                    throw new InvalidArgumentException(
                        "Template payload schema [{$path}.enum] contains a value outside type [{$type}].",
                    );
                }
            }
        }

        if ($type === 'object') {
            $this->assertObjectSchema($schema, $path, $depth);
        }

        if ($type === 'array' && isset($schema['items'])) {
            if (! is_array($schema['items'])) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}.items] must be an object.",
                );
            }

            $this->assertSchemaNode(
                $this->schemaMap($schema['items'], "{$path}.items"),
                "{$path}[]",
                $depth + 1,
            );
        }

        foreach (['minimum', 'maximum'] as $key) {
            if (isset($schema[$key])
                && (! is_int($schema[$key])
                    && ! is_float($schema[$key])
                    || ! is_finite((float) $schema[$key]))) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}.{$key}] must be a finite number.",
                );
            }
        }

        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $key) {
            if (isset($schema[$key])
                && (! is_int($schema[$key]) || $schema[$key] < 0)) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}.{$key}] must be a non-negative integer.",
                );
            }
        }

        foreach ([['minimum', 'maximum'], ['minLength', 'maxLength'], ['minItems', 'maxItems']] as [$minimum, $maximum]) {
            if (isset($schema[$minimum], $schema[$maximum])
                && $schema[$minimum] > $schema[$maximum]) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}.{$minimum}] cannot exceed [{$path}.{$maximum}].",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertObjectSchema(array $schema, string $path, int $depth): void
    {
        $properties = $this->schemaMap(
            $schema['properties'] ?? [],
            "{$path}.properties",
        );

        foreach ($properties as $property => $rules) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $property) !== 1
                || ! is_array($rules)) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}.properties] contains an invalid property.",
                );
            }

            $this->assertSchemaNode(
                $this->schemaMap($rules, "{$path}.{$property}"),
                "{$path}.{$property}",
                $depth + 1,
            );
        }

        $required = $schema['required'] ?? [];

        if (! is_array($required)) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}.required] must be an array.",
            );
        }

        foreach ($required as $property) {
            if (! is_string($property) || ! array_key_exists($property, $properties)) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}.required] references an unknown property.",
                );
            }
        }

        if (count($required) !== count(array_unique($required, SORT_REGULAR))) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}.required] must not contain duplicates.",
            );
        }

        if (isset($schema['additionalProperties'])
            && ! is_bool($schema['additionalProperties'])) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}.additionalProperties] must be boolean.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function allowedKeys(string $type): array
    {
        $common = ['type', 'nullable', 'enum'];

        return match ($type) {
            'array' => [...$common, 'items', 'minItems', 'maxItems'],
            'integer', 'number' => [...$common, 'minimum', 'maximum'],
            'object' => [
                ...$common,
                'properties',
                'required',
                'additionalProperties',
            ],
            'string' => [...$common, 'minLength', 'maxLength'],
            default => $common,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateValue(
        mixed $value,
        array $schema,
        string $path,
        int $depth,
    ): void {
        if ($value === null && ($schema['nullable'] ?? false) === true) {
            return;
        }

        $type = is_string($schema['type'] ?? null) ? $schema['type'] : '';

        if (! $this->matchesType($value, $type)) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] must be {$type}.",
            );
        }

        $enum = $schema['enum'] ?? null;

        if (is_array($enum) && ! in_array($value, $enum, true)) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] is not an allowed value.",
            );
        }

        if (is_string($value)) {
            $this->validateString($value, $schema, $path);
        }

        if (is_int($value) || is_float($value)) {
            $this->validateNumber($value, $schema, $path);
        }

        if ($type === 'object' && is_array($value)) {
            $this->validateObject($value, $schema, $path, $depth);
        }

        if ($type === 'array' && is_array($value)) {
            $this->validateArray($value, $schema, $path, $depth);
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'array' => is_array($value) && array_is_list($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'null' => $value === null,
            'number' => is_int($value) || is_float($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'string' => is_string($value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateString(string $value, array $schema, string $path): void
    {
        $length = mb_strlen($value);
        $minimum = $schema['minLength'] ?? null;
        $maximum = $schema['maxLength'] ?? null;

        if (is_numeric($minimum) && $length < (int) $minimum) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] is shorter than allowed.",
            );
        }

        if (is_numeric($maximum) && $length > (int) $maximum) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] is longer than allowed.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateNumber(int|float $value, array $schema, string $path): void
    {
        $minimum = $schema['minimum'] ?? null;
        $maximum = $schema['maximum'] ?? null;

        if (is_numeric($minimum) && $value < (float) $minimum) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] is below the allowed minimum.",
            );
        }

        if (is_numeric($maximum) && $value > (float) $maximum) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] exceeds the allowed maximum.",
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $schema
     */
    private function validateObject(
        array $value,
        array $schema,
        string $path,
        int $depth,
    ): void {
        $properties = $this->schemaMap(
            $schema['properties'] ?? [],
            "{$path}.properties",
        );
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $property) {
            if (is_string($property) && ! array_key_exists($property, $value)) {
                throw new TemplateResolutionException(
                    "Template payload [{$path}.{$property}] is required.",
                );
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            $unknown = array_diff(array_keys($value), array_keys($properties));

            if ($unknown !== []) {
                throw new TemplateResolutionException(
                    "Template payload [{$path}] contains an unknown property.",
                );
            }
        }

        foreach ($properties as $property => $rules) {
            if (is_array($rules) && array_key_exists($property, $value)) {
                $this->validateValue(
                    $value[$property],
                    $this->schemaMap($rules, "{$path}.{$property}"),
                    "{$path}.{$property}",
                    $depth + 1,
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $schema
     */
    private function validateArray(
        array $value,
        array $schema,
        string $path,
        int $depth,
    ): void {
        $minimum = $schema['minItems'] ?? null;
        $maximum = $schema['maxItems'] ?? null;

        if (is_numeric($minimum) && count($value) < (int) $minimum) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] has too few items.",
            );
        }

        if (is_numeric($maximum) && count($value) > (int) $maximum) {
            throw new TemplateResolutionException(
                "Template payload [{$path}] has too many items.",
            );
        }

        $items = $schema['items'] ?? null;

        if (! is_array($items)) {
            return;
        }

        $items = $this->schemaMap($items, "{$path}.items");

        foreach ($value as $index => $item) {
            $this->validateValue($item, $items, "{$path}.{$index}", $depth + 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaMap(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "Template payload schema [{$path}] must be an object.",
            );
        }

        $mapped = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "Template payload schema [{$path}] must use string keys.",
                );
            }

            $mapped[$key] = $item;
        }

        return $mapped;
    }
}
