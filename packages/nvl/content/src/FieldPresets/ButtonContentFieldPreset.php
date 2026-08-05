<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use Nvl\Content\Data\RenderedContentButtonData;
use Nvl\Content\Enums\ContentButtonEmphasis;
use Nvl\Content\Enums\ContentLinkTarget;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Defines and projects a reusable linked call-to-action.
 */
final class ButtonContentFieldPreset extends AbstractNavigationalContentFieldPreset
{
    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string
    {
        return 'button';
    }

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string
    {
        return 'Button';
    }

    /**
     * Return the editor-facing preset description.
     */
    public function description(): string
    {
        return 'Localized call-to-action copy, safe destination, and semantic emphasis.';
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
                ...$this->navigationFields(),
                [
                    'key' => 'emphasis',
                    'type' => 'select',
                    'label' => 'Emphasis',
                    'default' => 'primary',
                    'settings' => [
                        'options' => [
                            'primary' => 'Primary',
                            'secondary' => 'Secondary',
                            'tertiary' => 'Tertiary',
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
    ): ?RenderedContentButtonData {
        if (! is_array($value)
            || ! is_string($value['label'] ?? null)
            || ! is_string($value['href'] ?? null)) {
            return null;
        }

        $target = is_string($value['target'] ?? null)
            ? ContentLinkTarget::tryFrom($value['target'])
            : null;
        $target ??= ContentLinkTarget::SameContext;
        $emphasis = is_string($value['emphasis'] ?? null)
            ? ContentButtonEmphasis::tryFrom($value['emphasis'])
            : null;

        return new RenderedContentButtonData(
            label: $value['label'],
            href: $value['href'],
            title: is_string($value['title'] ?? null) ? $value['title'] : null,
            target: $target,
            rel: $this->secureRel($target, $this->relationshipValues($value['rel'] ?? [])),
            emphasis: $emphasis ?? ContentButtonEmphasis::Primary,
        );
    }
}
