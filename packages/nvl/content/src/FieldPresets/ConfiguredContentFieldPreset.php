<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

/**
 * Represents a configuration-defined semantic field preset with array rendering.
 */
final class ConfiguredContentFieldPreset extends AbstractContentFieldPreset
{
    /**
     * @param  array<string, mixed>  $fieldDefinition
     */
    public function __construct(
        private readonly string $presetAlias,
        private readonly string $presetName,
        private readonly ?string $presetDescription,
        private readonly array $fieldDefinition,
    ) {}

    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string
    {
        return $this->presetAlias;
    }

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string
    {
        return $this->presetName;
    }

    /**
     * Return the editor-facing preset description.
     */
    public function description(): ?string
    {
        return $this->presetDescription;
    }

    /**
     * Return the reusable field definition without a consumer-specific key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->fieldDefinition;
    }
}
