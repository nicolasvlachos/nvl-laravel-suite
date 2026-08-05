<?php

declare(strict_types=1);

namespace Nvl\Content\Validation;

use InvalidArgumentException;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Support\ContentUriSchemePolicy;

/**
 * Rejects misspelled or internally inconsistent settings for built-in fields.
 */
final class ContentFieldSettingsValidator
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'text' => ['min_length', 'max_length', 'pattern'],
        'textarea' => ['min_length', 'max_length', 'pattern'],
        'email' => ['min_length', 'max_length', 'pattern'],
        'color' => ['min_length', 'max_length', 'pattern'],
        'date' => ['min_length', 'max_length', 'pattern'],
        'date_time' => ['min_length', 'max_length', 'pattern'],
        'url' => ['min_length', 'max_length', 'pattern', 'allowed_schemes'],
        'uri' => ['min_length', 'max_length', 'pattern', 'allowed_schemes'],
        'select' => ['min_length', 'max_length', 'pattern', 'options'],
        'multi_select' => ['max_items', 'options'],
        'integer' => ['min', 'max'],
        'number' => ['min', 'max'],
        'boolean' => [],
        'rich_text' => [],
        'json' => ['schema'],
        'object' => [],
        'list' => ['min_items', 'max_items'],
        'repeater' => ['min_items', 'max_items'],
        'table' => ['min_items', 'max_items'],
        'media' => ['mime_types'],
        'media_collection' => ['mime_types', 'max_items'],
        'reference' => ['reference_type'],
        'reference_list' => ['reference_type', 'max_items'],
    ];

    public function validate(ContentFieldDefinition $field): void
    {
        $allowed = self::ALLOWED[$field->type] ?? null;

        if ($allowed === null) {
            return;
        }

        $unknown = array_diff(array_keys($field->settings), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] has unknown settings: ".implode(', ', $unknown).'.',
            );
        }

        $this->validateLength($field);
        $this->validateItems($field);
        $this->validateNumericBounds($field);
        $this->validateOptions($field);
        $this->validatePattern($field);
        $this->validateUrlSchemes($field);
        $this->validateMedia($field);
        $this->validateReference($field);
    }

    private function validateLength(ContentFieldDefinition $field): void
    {
        $global = ContentConfiguration::positiveInteger(
            'content.validation.maximum_string_length',
            100_000,
        );
        $minimum = $field->setting('min_length', 0);
        $maximum = $field->setting('max_length', $global);

        if (! is_int($minimum)
            || ! is_int($maximum)
            || $minimum < 0
            || $maximum < 1
            || $minimum > $maximum
            || $maximum > $global) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] has invalid string length settings.",
            );
        }
    }

    private function validateItems(ContentFieldDefinition $field): void
    {
        if (! in_array(
            $field->type,
            ['list', 'repeater', 'table', 'multi_select', 'media_collection', 'reference_list'],
            true,
        )) {
            return;
        }

        $global = ContentConfiguration::positiveInteger(
            $field->type === 'media_collection'
                ? 'content.media.maximum_per_field'
                : 'content.validation.maximum_items',
            $field->type === 'media_collection' ? 50 : 500,
        );
        $minimum = $field->setting('min_items', 0);
        $maximum = $field->setting('max_items', $global);

        if (! is_int($minimum)
            || ! is_int($maximum)
            || $minimum < 0
            || $maximum < 1
            || $minimum > $maximum
            || $maximum > $global) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] has invalid item-count settings.",
            );
        }
    }

    private function validateNumericBounds(ContentFieldDefinition $field): void
    {
        if (! in_array($field->type, ['integer', 'number'], true)) {
            return;
        }

        $minimum = $field->setting('min');
        $maximum = $field->setting('max');

        foreach (['min' => $minimum, 'max' => $maximum] as $name => $value) {
            if ($value !== null
                && (! is_int($value) && ! is_float($value)
                    || is_float($value) && ! is_finite($value))) {
                throw new InvalidArgumentException(
                    "Content field [{$field->key}] has an invalid {$name} bound.",
                );
            }
        }

        if ((is_int($minimum) || is_float($minimum))
            && (is_int($maximum) || is_float($maximum))
            && $minimum > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] has inverted numeric bounds.",
            );
        }
    }

    private function validateOptions(ContentFieldDefinition $field): void
    {
        if (! in_array($field->type, ['select', 'multi_select'], true)) {
            return;
        }

        $options = $field->setting('options');

        if (! is_array($options) || $options === []) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] requires non-empty string options.",
            );
        }

        foreach ($options as $key => $value) {
            $validKey = array_is_list($options)
                ? is_int($key)
                : is_string($key) && $key !== '';

            if (! $validKey || ! is_string($value) || $value === '') {
                throw new InvalidArgumentException(
                    "Content field [{$field->key}] has invalid select options.",
                );
            }
        }
    }

    private function validatePattern(ContentFieldDefinition $field): void
    {
        $pattern = $field->setting('pattern');

        if ($pattern !== null
            && (! is_string($pattern) || @preg_match($pattern, '') === false)) {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] has an invalid regular expression.",
            );
        }
    }

    private function validateUrlSchemes(ContentFieldDefinition $field): void
    {
        if (! in_array($field->type, ['url', 'uri'], true)) {
            return;
        }

        $schemes = $field->setting(
            'allowed_schemes',
            ContentConfiguration::stringList(
                $field->type === 'uri'
                    ? 'content.links.allowed_schemes'
                    : 'content.validation.url_schemes',
            ),
        );

        if (! is_array($schemes) || $schemes === []) {
            throw new InvalidArgumentException(
                "Content URL field [{$field->key}] requires allowed schemes.",
            );
        }

        ContentUriSchemePolicy::validateAllowedSchemes(
            $schemes,
            "Content URL field [{$field->key}] allowed_schemes",
        );
    }

    private function validateMedia(ContentFieldDefinition $field): void
    {
        if (! in_array($field->type, ['media', 'media_collection'], true)) {
            return;
        }

        $mimeTypes = $field->setting('mime_types', []);

        if (! is_array($mimeTypes)) {
            throw new InvalidArgumentException(
                "Content Media field [{$field->key}] mime_types must be an array.",
            );
        }

        foreach ($mimeTypes as $mimeType) {
            if (! is_string($mimeType)
                || preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+*-]+$~i', $mimeType) !== 1) {
                throw new InvalidArgumentException(
                    "Content Media field [{$field->key}] has an invalid MIME type.",
                );
            }
        }
    }

    private function validateReference(ContentFieldDefinition $field): void
    {
        if (! in_array($field->type, ['reference', 'reference_list'], true)) {
            return;
        }

        $alias = $field->setting('reference_type');

        if (! is_string($alias)
            || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException(
                "Content reference field [{$field->key}] has an invalid reference_type.",
            );
        }
    }
}
