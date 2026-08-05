<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\ContentRevisionRecorder;

/**
 * Removes a block from public resolution while preserving history and placements.
 */
final readonly class ArchiveContentBlockAction
{
    public function __construct(
        private ContentAuthorization $authorization,
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
                $model = ContentBlock::query()->lockForUpdate()->findOrFail($blockId);
                $this->authorization->authorize(ContentAbility::Archive, $actor, $model);

                if ($model->revision !== $expectedRevision) {
                    throw StaleContentException::forRevision(
                        $model->id,
                        $expectedRevision,
                        $model->revision,
                    );
                }

                $model->forceFill([
                    'status' => ContentStatus::Archived,
                    'revision' => $model->revision + 1,
                    'updated_by_type' => $actor->type,
                    'updated_by_id' => $actor->id,
                ])->save();
                $this->revisions->record($model, ContentRevisionEvent::Archived, $actor);
                ContentBlockChanged::dispatch(
                    $model->id,
                    ContentRevisionEvent::Archived,
                    $model->revision,
                    $actor,
                );

                return $model->refresh();
            });
    }
}
