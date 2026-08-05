<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use Nvl\Content\Data\RenderedContentHeadingData;
use Nvl\Content\Data\RenderedRichTextData;
use Nvl\Content\Enums\ContentHeadingLevel;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Defines and projects reusable localized heading copy with a structural level.
 */
final class HeadingContentFieldPreset extends AbstractContentFieldPreset
{
    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string
    {
        return 'heading';
    }

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string
    {
        return 'Heading';
    }

    /**
     * Return the editor-facing preset description.
     */
    public function description(): string
    {
        return 'Localized eyebrow, title, and rich description with a semantic heading level.';
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
                    'key' => 'eyebrow',
                    'type' => 'text',
                    'label' => 'Eyebrow',
                    'localized' => true,
                    'settings' => ['max_length' => 191],
                ],
                [
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'localized' => true,
                    'required' => true,
                    'settings' => ['max_length' => 255],
                ],
                [
                    'key' => 'description',
                    'type' => 'rich_text',
                    'label' => 'Description',
                    'localized' => true,
                ],
                [
                    'key' => 'level',
                    'type' => 'select',
                    'label' => 'Heading level',
                    'default' => 'h2',
                    'settings' => [
                        'options' => [
                            'h1' => 'Heading 1',
                            'h2' => 'Heading 2',
                            'h3' => 'Heading 3',
                            'h4' => 'Heading 4',
                            'h5' => 'Heading 5',
                            'h6' => 'Heading 6',
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
    ): ?RenderedContentHeadingData {
        if (! is_array($value) || ! is_string($value['title'] ?? null)) {
            return null;
        }

        $description = $value['description'] ?? null;
        $level = is_string($value['level'] ?? null)
            ? ContentHeadingLevel::tryFrom($value['level'])
            : null;

        return new RenderedContentHeadingData(
            eyebrow: is_string($value['eyebrow'] ?? null) ? $value['eyebrow'] : null,
            title: $value['title'],
            description: $description instanceof RenderedRichTextData || is_string($description)
                ? $description
                : null,
            level: $level ?? ContentHeadingLevel::H2,
        );
    }
}
