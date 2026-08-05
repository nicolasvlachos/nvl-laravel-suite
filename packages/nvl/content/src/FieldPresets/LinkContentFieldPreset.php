<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use Nvl\Content\Data\RenderedContentLinkData;
use Nvl\Content\Enums\ContentLinkTarget;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Defines and projects a reusable accessible navigation link.
 */
final class LinkContentFieldPreset extends AbstractNavigationalContentFieldPreset
{
    /**
     * Return the stable preset alias used by source-controlled schemas.
     */
    public function alias(): string
    {
        return 'link';
    }

    /**
     * Return the editor-facing preset name.
     */
    public function name(): string
    {
        return 'Link';
    }

    /**
     * Return the editor-facing preset description.
     */
    public function description(): string
    {
        return 'Accessible localized link copy with a safe internal or external destination.';
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
            'fields' => $this->navigationFields(),
        ];
    }

    /**
     * Project the recursively rendered preset value to its semantic contract.
     */
    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): ?RenderedContentLinkData {
        if (! is_array($value)
            || ! is_string($value['label'] ?? null)
            || ! is_string($value['href'] ?? null)) {
            return null;
        }

        $target = is_string($value['target'] ?? null)
            ? ContentLinkTarget::tryFrom($value['target'])
            : null;
        $target ??= ContentLinkTarget::SameContext;

        return new RenderedContentLinkData(
            label: $value['label'],
            href: $value['href'],
            title: is_string($value['title'] ?? null) ? $value['title'] : null,
            target: $target,
            rel: $this->secureRel($target, $this->relationshipValues($value['rel'] ?? [])),
        );
    }
}
