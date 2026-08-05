<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\ContentMediaSynchronizer;
use Nvl\Content\Services\ContentRevisionRecorder;
use Nvl\Content\Support\ContentArrays;

/**
 * Restores one deleted block as a draft and re-establishes valid Media links.
 */
final readonly class RestoreContentBlockAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentMediaSynchronizer $media,
        private ContentRevisionRecorder $revisions,
    ) {}

    public function execute(
        ContentBlock|string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): ContentBlock {
        $blockId = $block instanceof ContentBlock ? $block->id : $block;

        return DB::connection((new ContentBlock)->getConnectionName())
            ->transaction(function () use ($actor, $blockId, $expectedRevision): ContentBlock {
                $model = ContentBlock::withTrashed()
                    ->with(['definition', 'translations'])
                    ->lockForUpdate()
                    ->findOrFail($blockId);
                $this->authorization->authorize(ContentAbility::Restore, $actor, $model);

                if (! $model->trashed()) {
                    throw new InvalidArgumentException(
                        "Content block [{$model->id}] is not deleted.",
                    );
                }

                if ($model->revision !== $expectedRevision) {
                    throw StaleContentException::forRevision(
                        $model->id,
                        $expectedRevision,
                        $model->revision,
                    );
                }

                $model->forceFill([
                    'status' => ContentStatus::Draft,
                    'revision' => $model->revision + 1,
                    'published_by_type' => null,
                    'published_by_id' => null,
                    'published_at' => null,
                    'updated_by_type' => $actor->type,
                    'updated_by_id' => $actor->id,
                ])->restore();
                $translations = [];

                foreach ($model->translations as $translation) {
                    $locale = $translation->getAttribute('locale');
                    $values = $translation->getAttribute('values');

                    if (is_string($locale) && is_array($values)) {
                        $translations[$locale] = ContentArrays::stringMap(
                            $values,
                            "restored content translation {$locale}",
                        );
                    }
                }

                $this->media->synchronize(
                    $model,
                    $model->definition_schema,
                    is_array($model->values) ? $model->values : [],
                    $translations,
                    $actor,
                );
                $this->revisions->record($model, ContentRevisionEvent::Restored, $actor);
                ContentBlockChanged::dispatch(
                    $model->id,
                    ContentRevisionEvent::Restored,
                    $model->revision,
                    $actor,
                );

                return $model->refresh()->load(['definition', 'translations']);
            });
    }
}
