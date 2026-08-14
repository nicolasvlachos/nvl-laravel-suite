<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Resolves normalized content values through registered field and preset adapters.
 */
final readonly class ContentValueRenderer
{
    public function __construct(
        private ContentFieldTypeRegistry $fieldTypes,
        private ContentFieldPresetRegistry $presets,
        private ContentLocalePolicy $locales,
    ) {}

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
}
