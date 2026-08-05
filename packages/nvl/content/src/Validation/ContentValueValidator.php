<?php

declare(strict_types=1);

namespace Nvl\Content\Validation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Services\ContentLocalePolicy;
use Nvl\Content\Services\ContentLocalizedValues;
use Nvl\Content\Services\ContentRenderResources;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Bounded recursive validator for base, localized, and rendered block values.
 */
final readonly class ContentValueValidator
{
    public function __construct(
        private ContentFieldTypeRegistry $fieldTypes,
        private ContentFieldPresetRegistry $presets,
        private ContentSchemaValidator $schemas,
        private ContentLocalePolicy $locales,
        private ContentLocalizedValues $localizedValues,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function validate(
        ContentSchema $schema,
        array $values,
        array $translations,
        ContentActorData $actor,
        ContentVisibility $visibility,
        bool $publishing = false,
        ?Model $owner = null,
        ?string $group = null,
        bool $resolveExternal = true,
    ): ValidatedContentValues {
        $this->assertPayloadSize($values, $translations);
        $this->assertSchema($schema);
        $translationInputs = [];

        foreach ($translations as $locale => $localizedValues) {
            $normalizedLocale = $this->locales->assertSupported($locale);

            if (array_key_exists($normalizedLocale, $translationInputs)) {
                throw new InvalidArgumentException(
                    "Content translations contain duplicate locale [{$locale}].",
                );
            }

            $translationInputs[$normalizedLocale] = $localizedValues;
        }

        $normalizedValues = $this->normalizeRoot(
            schema: $schema,
            values: $values,
            actor: $actor,
            locale: $this->locales->current(),
            visibility: $visibility,
            localized: false,
            publishing: $publishing,
            owner: $owner,
            group: $group,
            resolveExternal: $resolveExternal,
        );
        $normalizedTranslations = [];

        foreach ($translationInputs as $normalizedLocale => $localizedValues) {
            $normalizedTranslations[$normalizedLocale] = $this->normalizeRoot(
                schema: $schema,
                values: $localizedValues,
                actor: $actor,
                locale: $normalizedLocale,
                visibility: $visibility,
                localized: true,
                publishing: $publishing,
                owner: $owner,
                group: $group,
                baseValues: $normalizedValues,
                resolveExternal: $resolveExternal,
            );
        }

        ksort($normalizedTranslations);
        $this->assertPayloadSize($normalizedValues, $normalizedTranslations);

        if ($publishing) {
            $this->assertRequiredLocalizedValues(
                $schema,
                $normalizedValues,
                $normalizedTranslations,
            );
        }

        $this->assertSemanticPresetValues(
            schema: $schema,
            baseValues: $normalizedValues,
            translations: $normalizedTranslations,
            actor: $actor,
            visibility: $visibility,
            publishing: $publishing,
            owner: $owner,
            group: $group,
            resolveExternal: $resolveExternal,
        );

        return new ValidatedContentValues($normalizedValues, $normalizedTranslations);
    }

    /**
     * Resolve field adapters for a merged, already normalized locale payload.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function render(
        ContentSchema $schema,
        array $values,
        ContentActorData $actor,
        string $locale,
        ContentVisibility $visibility,
        ?ContentRenderResources $resources = null,
        ?Model $owner = null,
        bool $publicOnly = false,
        ?string $group = null,
    ): array {
        $rendered = [];
        $context = new ContentValidationContext(
            actor: $actor,
            locale: $this->locales->assertSupported($locale),
            path: '',
            visibility: $visibility,
            resources: $resources,
            owner: $owner,
            publicOnly: $publicOnly,
            group: $group,
        );

        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $rendered[$field->key] = $this->renderField(
                $field,
                $values[$field->key],
                $context->nested($field->key),
                1,
            );
        }

        return $rendered;
    }

    /**
     * Validate every recursive schema field default without resolving external resources.
     */
    public function assertDefaults(ContentSchema $schema): void
    {
        $context = new ContentValidationContext(
            actor: ContentActorData::system(),
            locale: $this->locales->current(),
            path: '',
            visibility: ContentVisibility::Private,
            resolveExternal: false,
        );

        foreach ($schema->fields as $field) {
            $this->assertFieldDefaults(
                $field,
                $context->nested($field->key),
                1,
                false,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $baseValues
     * @return array<string, mixed>
     */
    private function normalizeRoot(
        ContentSchema $schema,
        array $values,
        ContentActorData $actor,
        string $locale,
        ContentVisibility $visibility,
        bool $localized,
        bool $publishing,
        ?Model $owner,
        ?string $group,
        array $baseValues = [],
        bool $resolveExternal = true,
    ): array {
        $allowed = [];

        foreach ($schema->fields as $field) {
            if ($this->localizedValues->includes($field, $localized)) {
                $allowed[$field->key] = $field;
            }
        }

        if ((bool) config('content.validation.reject_unknown_fields', true)) {
            $unknown = array_diff(array_keys($values), array_keys($allowed));

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'Unknown content fields: '.implode(', ', $unknown).'.',
                );
            }
        }

        $normalized = [];
        $context = new ContentValidationContext(
            actor: $actor,
            locale: $locale,
            path: '',
            visibility: $visibility,
            publishing: $publishing,
            owner: $owner,
            group: $group,
            localized: $localized,
            resolveExternal: $resolveExternal,
        );

        foreach ($allowed as $key => $field) {
            $hasValue = array_key_exists($key, $values);
            $useDefault = $field->localized === $localized;
            $value = $hasValue ? $values[$key] : ($useDefault ? $field->default : null);

            if (! $hasValue && (! $useDefault || $field->default === null)) {
                if ($publishing && $field->required && $field->localized === $localized) {
                    throw new InvalidArgumentException("Required content field [{$key}] is missing.");
                }

                continue;
            }

            if ($publishing
                && $field->required
                && $field->localized === $localized
                && $this->empty($value)) {
                throw new InvalidArgumentException("Required content field [{$key}] is empty.");
            }

            $normalized[$key] = $this->normalizeField(
                $field,
                $value,
                $context->nested($key),
                1,
                $localized,
                false,
                $baseValues[$key] ?? null,
            );
        }

        return $normalized;
    }

    private function normalizeField(
        ContentFieldDefinition $field,
        mixed $value,
        ContentValidationContext $context,
        int $depth,
        bool $localized,
        bool $ancestorLocalized,
        mixed $baseValue,
    ): mixed {
        $this->assertDepth($depth, $context);
        $normalized = $this->fieldTypes->get($field->type)->normalize($value, $field, $context);

        if ($normalized === null) {
            return null;
        }

        if ($field->type === 'object') {
            $normalized = $this->normalizeObject(
                $field,
                $normalized,
                $context,
                $depth,
                $localized,
                $ancestorLocalized,
                $baseValue,
            );
        }

        if (in_array($field->type, ['repeater', 'table'], true)) {
            $normalized = $this->normalizeRows(
                $field,
                $normalized,
                $context,
                $depth,
                $localized,
                $ancestorLocalized,
                $baseValue,
            );
        }

        if ($field->type === 'list') {
            $normalized = $this->normalizeList(
                $field,
                $normalized,
                $context,
                $depth,
                $localized,
                $ancestorLocalized,
                $baseValue,
            );
        }

        if ($field->preset !== null) {
            $normalized = $this->presets->get($field->preset)->normalize(
                $normalized,
                $field,
                $context,
            );
        }

        return $normalized;
    }

    /**
     * Validate one field default and every descendant default independently of payload presence.
     */
    private function assertFieldDefaults(
        ContentFieldDefinition $field,
        ContentValidationContext $context,
        int $depth,
        bool $ancestorLocalized,
    ): void {
        $localized = $ancestorLocalized || $field->localized;
        $fieldContext = new ContentValidationContext(
            actor: $context->actor,
            locale: $context->locale,
            path: $context->path,
            visibility: $context->visibility,
            localized: $localized,
            resolveExternal: false,
        );

        if ($field->default !== null) {
            $normalized = $this->normalizeField(
                field: $field,
                value: $field->default,
                context: $fieldContext,
                depth: $depth,
                localized: $localized,
                ancestorLocalized: $ancestorLocalized,
                baseValue: null,
            );
            $this->assertSemanticPresetField(
                $field,
                $normalized,
                $fieldContext,
                $depth,
            );
        }

        foreach ($field->fields as $child) {
            $this->assertFieldDefaults(
                $child,
                $fieldContext->nested($child->key),
                $depth + 1,
                $localized,
            );
        }

        if ($field->item !== null) {
            $this->assertFieldDefaults(
                $field->item,
                $fieldContext->nested($field->item->key),
                $depth + 1,
                $localized,
            );
        }
    }

    private function renderField(
        ContentFieldDefinition $field,
        mixed $value,
        ContentValidationContext $context,
        int $depth,
    ): mixed {
        $this->assertDepth($depth, $context);

        if ($value !== null && $field->type === 'object' && is_array($value)) {
            $value = $this->renderObject(
                $field,
                ContentArrays::stringMap($value, "rendered content {$context->path}"),
                $context,
                $depth,
            );
        } elseif ($value !== null
            && in_array($field->type, ['repeater', 'table'], true)
            && is_array($value)) {
            $value = array_map(
                fn (mixed $row, int $index): mixed => is_array($row)
                    ? $this->renderObject(
                        $field,
                        ContentArrays::stringMap(
                            $row,
                            "rendered content row {$context->path}.{$index}",
                        ),
                        $context->nested((string) $index),
                        $depth,
                    )
                    : $row,
                $value,
                array_keys($value),
            );
        } elseif ($value !== null
            && $field->type === 'list'
            && is_array($value)
            && $field->item !== null) {
            $value = array_map(
                fn (mixed $item, int $index): mixed => $this->renderField(
                    $field->item,
                    $item,
                    $context->nested((string) $index),
                    $depth + 1,
                ),
                $value,
                array_keys($value),
            );
        }

        $rendered = $this->fieldTypes->get($field->type)->render($value, $field, $context);

        return $field->preset !== null
            ? $this->presets->get($field->preset)->render($rendered, $field, $context)
            : $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeObject(
        ContentFieldDefinition $field,
        mixed $value,
        ContentValidationContext $context,
        int $depth,
        bool $localized,
        bool $ancestorLocalized,
        mixed $baseValue,
    ): array {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be an object.");
        }

        $effectiveLocalized = $ancestorLocalized || $field->localized;

        if ($localized
            && ! $effectiveLocalized
            && (! is_array($baseValue)
                || ($baseValue !== [] && array_is_list($baseValue)))) {
            throw new InvalidArgumentException(
                "Localized content object [{$context->path}] has no matching base object.",
            );
        }

        return $this->normalizeChildren(
            $field,
            ContentArrays::stringMap($value, "content object {$context->path}"),
            $context,
            $depth,
            $localized,
            $ancestorLocalized || $field->localized,
            is_array($baseValue)
                && ($baseValue === [] || ! array_is_list($baseValue))
                ? ContentArrays::stringMap(
                    $baseValue,
                    "content object {$context->path} base",
                )
                : [],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(
        ContentFieldDefinition $field,
        mixed $value,
        ContentValidationContext $context,
        int $depth,
        bool $localized,
        bool $ancestorLocalized,
        mixed $baseValue,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be a row list.");
        }

        $this->assertItemCount($field, $value, $context);
        $rows = [];
        $keys = [];
        $baseRows = is_array($baseValue) && array_is_list($baseValue)
            ? $baseValue
            : [];
        $baseRowsByKey = [];

        foreach ($baseRows as $baseIndex => $baseRow) {
            if (is_array($baseRow) && is_string($baseRow['_key'] ?? null)) {
                $baseRowsByKey[$baseRow['_key']] = [
                    'index' => $baseIndex,
                    'row' => $baseRow,
                ];
            }
        }

        $effectiveLocalized = $ancestorLocalized || $field->localized;
        $localizedStructure = $localized && ! $effectiveLocalized;

        foreach ($value as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException(
                    "Content row [{$context->path}.{$index}] must be an object.",
                );
            }

            $key = $row['_key'] ?? null;
            unset($row['_key']);

            if ($localizedStructure
                && $field->type === 'repeater'
                && ! is_string($key)) {
                throw new InvalidArgumentException(
                    "Localized content repeater [{$context->path}] row [{$index}] has no matching base row key.",
                );
            }

            if ($localizedStructure
                && $field->type === 'repeater'
                && is_string($key)
                && ! isset($baseRowsByKey[$key])) {
                throw new InvalidArgumentException(
                    "Localized content repeater [{$context->path}] row [{$index}] has an unknown base row key.",
                );
            }

            if ($localizedStructure
                && $field->type === 'table'
                && ! array_key_exists($index, $baseRows)) {
                throw new InvalidArgumentException(
                    "Localized content table [{$context->path}] row [{$index}] has no matching base row.",
                );
            }

            $baseRow = is_string($key) && isset($baseRowsByKey[$key])
                ? $baseRowsByKey[$key]['row']
                : ($baseRows[$index] ?? []);
            $normalized = $this->normalizeChildren(
                $field,
                ContentArrays::stringMap(
                    $row,
                    "content row {$context->path}.{$index}",
                ),
                $context->nested((string) $index),
                $depth,
                $localized,
                $effectiveLocalized,
                is_array($baseRow) && ! array_is_list($baseRow)
                    ? ContentArrays::stringMap(
                        $baseRow,
                        "content row {$context->path}.{$index} base",
                    )
                    : [],
            );

            if ($field->type === 'repeater') {
                $key = is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key) === 1
                    ? $key
                    : ($localizedStructure ? null : (string) Str::uuid());

                if ($key === null) {
                    throw new InvalidArgumentException(
                        "Localized content repeater [{$context->path}] row [{$index}] has no matching base row key.",
                    );
                }

                if (isset($keys[$key])) {
                    throw new InvalidArgumentException(
                        "Content repeater [{$context->path}] contains duplicate row key [{$key}].",
                    );
                }

                $keys[$key] = true;
                $normalized = [
                    '_key' => $key,
                    ...$normalized,
                ];
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    private function normalizeList(
        ContentFieldDefinition $field,
        mixed $value,
        ContentValidationContext $context,
        int $depth,
        bool $localized,
        bool $ancestorLocalized,
        mixed $baseValue,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be a list.");
        }

        if ($field->item === null) {
            throw new InvalidArgumentException(
                "Content list field [{$context->path}] requires an item definition.",
            );
        }

        $this->assertItemCount($field, $value, $context);
        $baseItems = is_array($baseValue) && array_is_list($baseValue)
            ? $baseValue
            : [];

        if ($localized
            && ! ($ancestorLocalized || $field->localized)
            && count($value) > count($baseItems)) {
            throw new InvalidArgumentException(
                "Localized content list [{$context->path}] has items without matching base values.",
            );
        }

        return array_map(
            fn (mixed $item, int $index): mixed => $this->normalizeField(
                $field->item,
                $item,
                $context->nested((string) $index),
                $depth + 1,
                $localized,
                $ancestorLocalized || $field->localized,
                $baseItems[$index] ?? null,
            ),
            $value,
            array_keys($value),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $baseValues
     * @return array<string, mixed>
     */
    private function normalizeChildren(
        ContentFieldDefinition $parent,
        array $values,
        ContentValidationContext $context,
        int $depth,
        bool $localized,
        bool $ancestorLocalized,
        array $baseValues,
    ): array {
        $definitions = [];

        foreach ($parent->fields as $field) {
            if ($this->localizedValues->includes(
                $field,
                $localized,
                $ancestorLocalized,
            )) {
                $definitions[$field->key] = $field;
            }
        }

        if ((bool) config('content.validation.reject_unknown_fields', true)) {
            $unknown = array_diff(array_keys($values), array_keys($definitions));

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    "Unknown nested fields under [{$context->path}]: ".implode(', ', $unknown).'.',
                );
            }
        }

        $normalized = [];

        foreach ($definitions as $key => $field) {
            $hasValue = array_key_exists($key, $values);
            $effectiveLocalized = $ancestorLocalized || $field->localized;
            $useDefault = $effectiveLocalized === $localized;
            $value = $hasValue ? $values[$key] : ($useDefault ? $field->default : null);

            if (! $hasValue && (! $useDefault || $field->default === null)) {
                if ($context->publishing
                    && $field->required
                    && $effectiveLocalized === $localized) {
                    throw new InvalidArgumentException(
                        "Required content field [{$context->path}.{$key}] is missing.",
                    );
                }

                continue;
            }

            if ($context->publishing
                && $field->required
                && $effectiveLocalized === $localized
                && $this->empty($value)) {
                throw new InvalidArgumentException(
                    "Required content field [{$context->path}.{$key}] is empty.",
                );
            }

            $normalized[$key] = $this->normalizeField(
                $field,
                $value,
                $context->nested($key),
                $depth + 1,
                $localized,
                $ancestorLocalized,
                $baseValues[$key] ?? null,
            );
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function renderObject(
        ContentFieldDefinition $parent,
        array $values,
        ContentValidationContext $context,
        int $depth,
    ): array {
        $rendered = [];

        if (isset($values['_key']) && is_string($values['_key'])) {
            $rendered['_key'] = $values['_key'];
        }

        foreach ($parent->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $rendered[$field->key] = $this->renderField(
                $field,
                $values[$field->key],
                $context->nested($field->key),
                $depth + 1,
            );
        }

        return $rendered;
    }

    /**
     * Validate semantic invariants against complete locale-resolved values.
     *
     * @param  array<string, mixed>  $baseValues
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function assertSemanticPresetValues(
        ContentSchema $schema,
        array $baseValues,
        array $translations,
        ContentActorData $actor,
        ContentVisibility $visibility,
        bool $publishing,
        ?Model $owner,
        ?string $group,
        bool $resolveExternal,
    ): void {
        $locales = array_keys($translations);

        if ($publishing) {
            $requiredLocales = ContentConfiguration::stringList(
                'content.locales.required_on_publish',
            );
            $locales = [
                ...$locales,
                ...($requiredLocales === [] ? $this->locales->available() : $requiredLocales),
            ];
        }

        if ($locales === []) {
            $locales[] = $this->locales->current();
        }

        $locales = array_values(array_unique(array_map(
            $this->locales->assertSupported(...),
            $locales,
        )));
        sort($locales);

        foreach ($locales as $locale) {
            $values = $this->localizedValues->overlay(
                $schema,
                $baseValues,
                $this->localizedValues->resolve($schema, $translations, $locale),
            );
            $context = new ContentValidationContext(
                actor: $actor,
                locale: $locale,
                path: '',
                visibility: $visibility,
                publishing: $publishing,
                owner: $owner,
                group: $group,
                resolveExternal: $resolveExternal,
            );

            foreach ($schema->fields as $field) {
                if (array_key_exists($field->key, $values)) {
                    $this->assertSemanticPresetField(
                        $field,
                        $values[$field->key],
                        $context->nested($field->key),
                        1,
                    );
                }
            }
        }
    }

    /**
     * Validate one recursively merged field and every semantic child.
     */
    private function assertSemanticPresetField(
        ContentFieldDefinition $field,
        mixed $value,
        ContentValidationContext $context,
        int $depth,
    ): void {
        $this->assertDepth($depth, $context);

        if ($value !== null && $field->type === 'object' && is_array($value)) {
            $this->assertSemanticPresetChildren(
                $field,
                $value,
                $context,
                $depth,
            );
        } elseif ($value !== null
            && in_array($field->type, ['repeater', 'table'], true)
            && is_array($value)) {
            foreach ($value as $index => $row) {
                if (is_array($row)) {
                    $this->assertSemanticPresetChildren(
                        $field,
                        $row,
                        $context->nested((string) $index),
                        $depth,
                    );
                }
            }
        } elseif ($value !== null
            && $field->type === 'list'
            && $field->item !== null
            && is_array($value)) {
            foreach ($value as $index => $item) {
                $this->assertSemanticPresetField(
                    $field->item,
                    $item,
                    $context->nested((string) $index),
                    $depth + 1,
                );
            }
        }

        if ($field->preset !== null) {
            $this->presets->get($field->preset)->validate($value, $field, $context);
        }
    }

    /**
     * Validate semantic children contained by one object or structured row.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function assertSemanticPresetChildren(
        ContentFieldDefinition $parent,
        array $values,
        ContentValidationContext $context,
        int $depth,
    ): void {
        foreach ($parent->fields as $field) {
            if (array_key_exists($field->key, $values)) {
                $this->assertSemanticPresetField(
                    $field,
                    $values[$field->key],
                    $context->nested($field->key),
                    $depth + 1,
                );
            }
        }
    }

    public function assertSchema(ContentSchema $schema): void
    {
        $this->schemas->validate($schema);
    }

    /**
     * @param  array<string, mixed>  $baseValues
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function assertRequiredLocalizedValues(
        ContentSchema $schema,
        array $baseValues,
        array $translations,
    ): void {
        if (! $this->hasRequiredLocalizedFields($schema)) {
            return;
        }

        $requiredLocales = ContentConfiguration::stringList(
            'content.locales.required_on_publish',
        );

        if ($requiredLocales === []) {
            $requiredLocales = $this->locales->available();
        }

        if ($requiredLocales === []) {
            throw new InvalidArgumentException(
                'Published content requires at least one translation for localized required fields.',
            );
        }

        foreach ($requiredLocales as $locale) {
            $normalizedLocale = $this->locales->assertSupported($locale);
            $values = $translations[$normalizedLocale] ?? [];

            foreach ($schema->fields as $field) {
                $this->assertRequiredLocalizedField(
                    field: $field,
                    baseValue: $baseValues[$field->key] ?? null,
                    localizedValue: $values[$field->key] ?? null,
                    path: $field->key,
                    locale: $normalizedLocale,
                    ancestorLocalized: false,
                    parentPresent: true,
                );
            }
        }
    }

    private function hasRequiredLocalizedFields(ContentSchema $schema): bool
    {
        foreach ($schema->fields as $field) {
            if ($this->fieldHasRequiredLocalizedValue($field, false)) {
                return true;
            }
        }

        return false;
    }

    private function fieldHasRequiredLocalizedValue(
        ContentFieldDefinition $field,
        bool $ancestorLocalized,
    ): bool {
        $effectiveLocalized = $ancestorLocalized || $field->localized;

        if ($effectiveLocalized && $field->required) {
            return true;
        }

        foreach ($field->fields as $child) {
            if ($this->fieldHasRequiredLocalizedValue($child, $effectiveLocalized)) {
                return true;
            }
        }

        return $field->item !== null
            && $this->fieldHasRequiredLocalizedValue($field->item, $effectiveLocalized);
    }

    private function assertRequiredLocalizedField(
        ContentFieldDefinition $field,
        mixed $baseValue,
        mixed $localizedValue,
        string $path,
        string $locale,
        bool $ancestorLocalized,
        bool $parentPresent,
    ): void {
        $effectiveLocalized = $ancestorLocalized || $field->localized;

        if ($effectiveLocalized) {
            if ($field->required && $parentPresent && $this->empty($localizedValue)) {
                throw new InvalidArgumentException(
                    "Required localized field [{$path}] is missing for [{$locale}].",
                );
            }

            if ($localizedValue === null) {
                return;
            }

            $this->assertRequiredLocalizedChildren(
                $field,
                null,
                $localizedValue,
                $path,
                $locale,
                true,
            );

            return;
        }

        $present = $baseValue !== null || $localizedValue !== null;

        if (! $present) {
            return;
        }

        $this->assertRequiredLocalizedChildren(
            $field,
            $baseValue,
            $localizedValue,
            $path,
            $locale,
            false,
        );
    }

    private function assertRequiredLocalizedChildren(
        ContentFieldDefinition $field,
        mixed $baseValue,
        mixed $localizedValue,
        string $path,
        string $locale,
        bool $ancestorLocalized,
    ): void {
        if ($field->type === 'object') {
            $base = is_array($baseValue) && ! array_is_list($baseValue) ? $baseValue : [];
            $localized = is_array($localizedValue) && ! array_is_list($localizedValue)
                ? $localizedValue
                : [];

            foreach ($field->fields as $child) {
                $this->assertRequiredLocalizedField(
                    field: $child,
                    baseValue: $base[$child->key] ?? null,
                    localizedValue: $localized[$child->key] ?? null,
                    path: "{$path}.{$child->key}",
                    locale: $locale,
                    ancestorLocalized: $ancestorLocalized,
                    parentPresent: true,
                );
            }

            return;
        }

        if (in_array($field->type, ['repeater', 'table'], true)) {
            $this->assertRequiredLocalizedRows(
                $field,
                $baseValue,
                $localizedValue,
                $path,
                $locale,
                $ancestorLocalized,
            );

            return;
        }

        if ($field->type === 'list' && $field->item !== null) {
            $base = is_array($baseValue) && array_is_list($baseValue) ? $baseValue : [];
            $localized = is_array($localizedValue) && array_is_list($localizedValue)
                ? $localizedValue
                : [];
            $count = max(count($base), count($localized));

            for ($index = 0; $index < $count; $index++) {
                $this->assertRequiredLocalizedField(
                    field: $field->item,
                    baseValue: $base[$index] ?? null,
                    localizedValue: $localized[$index] ?? null,
                    path: "{$path}.{$index}",
                    locale: $locale,
                    ancestorLocalized: $ancestorLocalized,
                    parentPresent: true,
                );
            }
        }
    }

    private function assertRequiredLocalizedRows(
        ContentFieldDefinition $field,
        mixed $baseValue,
        mixed $localizedValue,
        string $path,
        string $locale,
        bool $ancestorLocalized,
    ): void {
        $baseRows = is_array($baseValue) && array_is_list($baseValue) ? $baseValue : [];
        $localizedRows = is_array($localizedValue) && array_is_list($localizedValue)
            ? $localizedValue
            : [];
        $localizedByKey = [];

        foreach ($localizedRows as $localizedRow) {
            if (is_array($localizedRow) && is_string($localizedRow['_key'] ?? null)) {
                $localizedByKey[$localizedRow['_key']] = $localizedRow;
            }
        }

        $count = max(count($baseRows), count($localizedRows));

        for ($index = 0; $index < $count; $index++) {
            $baseRow = is_array($baseRows[$index] ?? null) ? $baseRows[$index] : [];
            $key = is_string($baseRow['_key'] ?? null) ? $baseRow['_key'] : null;
            $localizedRow = $key !== null
                ? ($localizedByKey[$key] ?? [])
                : (is_array($localizedRows[$index] ?? null) ? $localizedRows[$index] : []);

            foreach ($field->fields as $child) {
                $this->assertRequiredLocalizedField(
                    field: $child,
                    baseValue: $baseRow[$child->key] ?? null,
                    localizedValue: $localizedRow[$child->key] ?? null,
                    path: "{$path}.{$index}.{$child->key}",
                    locale: $locale,
                    ancestorLocalized: $ancestorLocalized,
                    parentPresent: true,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function assertPayloadSize(array $values, array $translations): void
    {
        $encoded = json_encode(
            ['values' => $values, 'translations' => $translations],
            JSON_THROW_ON_ERROR,
        );
        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_payload_bytes',
            524_288,
        );

        if (strlen($encoded) > $maximum) {
            throw new InvalidArgumentException(
                "Content values exceed the configured {$maximum} byte limit.",
            );
        }
    }

    private function assertDepth(
        int $depth,
        ContentValidationContext $context,
    ): void {
        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_depth',
            12,
        );

        if ($depth > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] exceeds the {$maximum} level depth limit.",
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private function assertItemCount(
        ContentFieldDefinition $field,
        array $items,
        ContentValidationContext $context,
    ): void {
        $globalMaximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_items',
            500,
        );
        $maximum = $field->setting('max_items', $globalMaximum);
        $minimum = $field->setting('min_items', 0);

        if (! is_int($maximum) || $maximum < 1 || $maximum > $globalMaximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] has an invalid max_items setting.",
            );
        }

        if (! is_int($minimum) || $minimum < 0 || $minimum > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] has an invalid min_items setting.",
            );
        }

        if (count($items) < $minimum || count($items) > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] item count is outside its bounds.",
            );
        }
    }

    private function empty(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }
}
