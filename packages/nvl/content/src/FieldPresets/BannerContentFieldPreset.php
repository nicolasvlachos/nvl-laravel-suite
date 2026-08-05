<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use Nvl\Content\Data\RenderedContentBannerData;
use Nvl\Content\Data\RenderedContentButtonData;
use Nvl\Content\Data\RenderedContentHeadingData;
use Nvl\Content\Data\RenderedContentImageData;
use Nvl\Content\Enums\ContentAlignment;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Defines and projects a reusable banner assembled from other semantic presets.
 */
final class BannerContentFieldPreset extends AbstractContentFieldPreset
{
    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string
    {
        return 'banner';
    }

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string
    {
        return 'Banner';
    }

    /**
     * Return the editor-facing preset description.
     */
    public function description(): string
    {
        return 'Heading, image, primary and secondary actions, and semantic alignment.';
    }

    /**
     * Return the reusable field definition without a consumer-specific key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'object',
            'fields' => [
                [
                    'key' => 'heading',
                    'preset' => 'heading',
                    'label' => 'Heading',
                    'required' => true,
                    'default' => ['level' => 'h1'],
                ],
                [
                    'key' => 'image',
                    'preset' => 'image',
                    'label' => 'Image',
                ],
                [
                    'key' => 'primary_action',
                    'preset' => 'button',
                    'label' => 'Primary action',
                ],
                [
                    'key' => 'secondary_action',
                    'preset' => 'button',
                    'label' => 'Secondary action',
                ],
                [
                    'key' => 'alignment',
                    'type' => 'select',
                    'label' => 'Alignment',
                    'default' => 'start',
                    'settings' => [
                        'options' => [
                            'start' => 'Start',
                            'center' => 'Center',
                            'end' => 'End',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Project the recursively rendered preset value to its semantic contract.
     */
    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): ?RenderedContentBannerData {
        if (! is_array($value)
            || ! (($value['heading'] ?? null) instanceof RenderedContentHeadingData)) {
            return null;
        }

        $image = $value['image'] ?? null;
        $primaryAction = $value['primary_action'] ?? null;
        $secondaryAction = $value['secondary_action'] ?? null;
        $alignment = is_string($value['alignment'] ?? null)
            ? ContentAlignment::tryFrom($value['alignment'])
            : null;

        return new RenderedContentBannerData(
            heading: $value['heading'],
            image: $image instanceof RenderedContentImageData ? $image : null,
            primaryAction: $primaryAction instanceof RenderedContentButtonData
                ? $primaryAction
                : null,
            secondaryAction: $secondaryAction instanceof RenderedContentButtonData
                ? $secondaryAction
                : null,
            alignment: $alignment ?? ContentAlignment::Start,
        );
    }
}
