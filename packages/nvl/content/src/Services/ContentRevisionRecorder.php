<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentRevision;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Writes bounded immutable snapshots after each successful block mutation.
 */
final readonly class ContentRevisionRecorder
{
    public function __construct(private CanonicalJson $json) {}

    public function record(
        ContentBlock $block,
        ContentRevisionEvent $event,
        ContentActorData $actor,
    ): ContentRevision {
        $block->loadMissing('translations');
        $translations = [];

        foreach ($block->translations as $translation) {
            $locale = $translation->getAttribute('locale');
            $values = $translation->getAttribute('values');

            if (is_string($locale) && is_array($values)) {
                $translations[$locale] = $values;
            }
        }

        ksort($translations);
        $snapshot = [
            'definition_id' => $block->definition_id,
            'key' => $block->key,
            'scope' => $block->scope,
            'scope_key' => $block->scope_key,
            'status' => $block->status->value,
            'visibility' => $block->visibility->value,
            'values' => $block->values ?? [],
            'translations' => $translations,
            'metadata' => $block->metadata ?? [],
            'definition_version' => $block->definition_version,
            'definition_hash' => $block->definition_hash,
            'definition_schema' => $block->definition_schema->toArray(),
            'definition_view' => $block->definition_view,
            'revision' => $block->revision,
        ];

        $maximum = ContentConfiguration::positiveInteger(
            'content.validation.maximum_revision_bytes',
            2_097_152,
        );

        if (strlen($this->json->encode($snapshot)) > $maximum) {
            throw new InvalidArgumentException(
                "Content revision snapshot exceeds {$maximum} bytes.",
            );
        }

        return ContentRevision::query()->create([
            'content_block_id' => $block->id,
            'revision' => $block->revision,
            'event' => $event,
            'snapshot' => $snapshot,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
        ]);
    }
}
