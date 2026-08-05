<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use Nvl\Content\Contracts\ContentFieldPreset;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Supplies pass-through normalization and rendering for semantic field presets.
 */
abstract class AbstractContentFieldPreset implements ContentFieldPreset
{
    /**
     * Return the editor-facing preset description.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * Normalize one recursively validated base or localized preset partition.
     */
    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed {
        return $value;
    }

    /**
     * Validate one complete schema-aware merged semantic value.
     */
    public function validate(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void {}

    /**
     * Add semantic constraints and annotations to the generated JSON Schema fragment.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $schema,
        ContentFieldDefinition $field,
    ): array {
        return $schema;
    }

    /**
     * Project the recursively rendered preset value to its semantic contract.
     */
    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed {
        return $value;
    }
}
