<?php

declare(strict_types=1);

namespace Nvl\Content\FieldPresets;

use Nvl\Content\Enums\ContentLinkRelationship;
use Nvl\Content\Enums\ContentLinkTarget;

/**
 * Shares the bounded schema and secure relationship projection used by links and buttons.
 */
abstract class AbstractNavigationalContentFieldPreset extends AbstractContentFieldPreset
{
    /**
     * Return the common semantic navigation fields.
     *
     * @return list<array<string, mixed>>
     */
    protected function navigationFields(): array
    {
        return [
            [
                'key' => 'label',
                'type' => 'text',
                'label' => 'Label',
                'localized' => true,
                'required' => true,
                'settings' => ['max_length' => 191],
            ],
            [
                'key' => 'href',
                'type' => 'uri',
                'label' => 'Destination',
                'required' => true,
            ],
            [
                'key' => 'title',
                'type' => 'text',
                'label' => 'Accessible title',
                'localized' => true,
                'settings' => ['max_length' => 255],
            ],
            [
                'key' => 'target',
                'type' => 'select',
                'label' => 'Target',
                'default' => '_self',
                'settings' => [
                    'options' => [
                        '_self' => 'Same context',
                        '_blank' => 'New context',
                    ],
                ],
            ],
            [
                'key' => 'rel',
                'type' => 'multi_select',
                'label' => 'Relationship',
                'default' => [],
                'settings' => [
                    'max_items' => 5,
                    'options' => [
                        'nofollow' => 'No follow',
                        'noopener' => 'No opener',
                        'noreferrer' => 'No referrer',
                        'sponsored' => 'Sponsored',
                        'ugc' => 'User generated',
                    ],
                ],
            ],
        ];
    }

    /**
     * Return a normalized secure relationship list for one navigation target.
     *
     * @param  list<ContentLinkRelationship>  $rel
     * @return list<ContentLinkRelationship>
     */
    protected function secureRel(ContentLinkTarget $target, array $rel): array
    {
        if ($target === ContentLinkTarget::NewContext) {
            $rel = [
                ...$rel,
                ContentLinkRelationship::NoOpener,
                ContentLinkRelationship::NoReferrer,
            ];
        }

        $relationships = [];

        foreach ($rel as $relationship) {
            $relationships[$relationship->value] = $relationship;
        }

        ksort($relationships);

        return array_values($relationships);
    }

    /**
     * Return only supported relationship tokens.
     *
     * @return list<ContentLinkRelationship>
     */
    protected function relationshipValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $relationships = [];

        foreach ($value as $relationship) {
            $resolved = is_string($relationship)
                ? ContentLinkRelationship::tryFrom($relationship)
                : null;

            if ($resolved instanceof ContentLinkRelationship) {
                $relationships[] = $resolved;
            }
        }

        return $relationships;
    }
}
