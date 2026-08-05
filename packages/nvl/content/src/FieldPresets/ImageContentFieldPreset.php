<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use InvalidArgumentException;
use Nvl\Content\Data\RenderedContentImageData;
use Nvl\Content\Data\RenderedPrivateMediaData;
use Nvl\Content\Data\RenderedRichTextData;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Media\Data\Display\PublicMedia;

/**
 * Defines and projects an image asset with content-owned localized accessibility metadata.
 */
final class ImageContentFieldPreset extends AbstractContentFieldPreset
{
    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string
    {
        return 'image';
    }

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string
    {
        return 'Image';
    }

    /**
     * Return the editor-facing preset description.
     */
    public function description(): string
    {
        return 'Media-backed image with localized alt, title, caption, credit, and focal point.';
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
                    'key' => 'media',
                    'type' => 'media',
                    'label' => 'Image',
                    'required' => true,
                    'settings' => ['mime_types' => ['image/*']],
                ],
                [
                    'key' => 'alt',
                    'type' => 'text',
                    'label' => 'Alternative text',
                    'localized' => true,
                    'settings' => ['max_length' => 500],
                ],
                [
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'localized' => true,
                    'settings' => ['max_length' => 255],
                ],
                [
                    'key' => 'caption',
                    'type' => 'rich_text',
                    'label' => 'Caption',
                    'localized' => true,
                ],
                [
                    'key' => 'credit',
                    'type' => 'text',
                    'label' => 'Credit',
                    'localized' => true,
                    'settings' => ['max_length' => 255],
                ],
                [
                    'key' => 'decorative',
                    'type' => 'boolean',
                    'label' => 'Decorative',
                    'default' => false,
                ],
                [
                    'key' => 'focal_x',
                    'type' => 'number',
                    'label' => 'Horizontal focal point',
                    'settings' => ['min' => 0, 'max' => 1],
                ],
                [
                    'key' => 'focal_y',
                    'type' => 'number',
                    'label' => 'Vertical focal point',
                    'settings' => ['min' => 0, 'max' => 1],
                ],
            ],
        ];
    }

    /**
     * Require locale-resolved alternative text for every published non-decorative image.
     */
    public function validate(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void {
        if (! $context->publishing || ! is_array($value) || ($value['decorative'] ?? false) === true) {
            return;
        }

        $media = $value['media'] ?? null;
        $alt = $value['alt'] ?? null;

        if ($media !== null && (! is_string($alt) || trim($alt) === '')) {
            throw new InvalidArgumentException(
                "Non-decorative image [{$context->path}] requires alternative text for [{$context->locale}].",
            );
        }
    }

    /**
     * Describe the same alternative-text invariant to generic JSON Schema consumers.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $schema,
        ContentFieldDefinition $field,
    ): array {
        $constraints = $schema['allOf'] ?? [];
        $schema['allOf'] = [
            ...(is_array($constraints) ? $constraints : []),
            [
                'if' => [
                    'not' => [
                        'properties' => [
                            'decorative' => ['const' => true],
                        ],
                        'required' => ['decorative'],
                    ],
                ],
                'then' => [
                    'properties' => [
                        'alt' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'pattern' => '\\S',
                        ],
                    ],
                    'required' => ['alt'],
                ],
            ],
        ];

        return $schema;
    }

    /**
     * Project the recursively rendered preset value to its semantic contract.
     */
    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): ?RenderedContentImageData {
        if (! is_array($value)) {
            return null;
        }

        $media = $value['media'] ?? null;

        if (! $media instanceof PublicMedia
            && ! $media instanceof RenderedPrivateMediaData) {
            $media = null;
        }

        $decorative = ($value['decorative'] ?? false) === true;
        $caption = $value['caption'] ?? null;

        return new RenderedContentImageData(
            media: $media,
            alt: $decorative
                ? ''
                : (is_string($value['alt'] ?? null) ? $value['alt'] : null),
            title: is_string($value['title'] ?? null) ? $value['title'] : null,
            caption: $caption instanceof RenderedRichTextData
                || is_string($caption)
                    ? $caption
                    : null,
            credit: is_string($value['credit'] ?? null) ? $value['credit'] : null,
            decorative: $decorative,
            focalX: is_int($value['focal_x'] ?? null) || is_float($value['focal_x'] ?? null)
                ? (float) $value['focal_x']
                : null,
            focalY: is_int($value['focal_y'] ?? null) || is_float($value['focal_y'] ?? null)
                ? (float) $value['focal_y']
                : null,
        );
    }
}
