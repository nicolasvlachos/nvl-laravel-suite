<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Defines one reusable semantic field schema and its normalized/rendered projection.
 */
interface ContentFieldPreset
{
    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string;

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string;

    /**
     * Return the editor-facing preset description.
     */
    public function description(): ?string;

    /**
     * Return the reusable field definition without a consumer-specific key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Normalize one recursively validated base or localized preset partition.
     */
    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed;

    /**
     * Validate one complete schema-aware merged semantic value.
     */
    public function validate(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void;

    /**
     * Add semantic constraints and annotations to the generated JSON Schema fragment.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $schema,
        ContentFieldDefinition $field,
    ): array;

    /**
     * Project the recursively rendered preset value to its semantic contract.
     */
    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed;
}
