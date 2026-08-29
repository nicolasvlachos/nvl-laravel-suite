<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentPlacementOwnerLock;
use Nvl\Content\Services\ContentPlacementValidator;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Atomically replaces the reusable block behind one owner placement.
 */
final readonly class ReplaceContentPlacementAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentPlacementValidator $validator,
        private ContentPlacementOwnerLock $ownerLocks,
    ) {}

    /**
     * Replace one exact owner-group placement block at its expected revision.
     */
    public function execute(
        Model&ContentOwner $owner,
        string $group,
        string $placement,
        string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): ContentPlacementData {
        if (! Str::isUuid($placement) || ! Str::isUuid($block)) {
            throw new InvalidArgumentException(
                'Content placement and replacement block identifiers must be UUIDs.',
            );
        }

        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $this->owners->assertGroup($owner, $group);

        return $this->ownerLocks->run(
            $ownerType,
            $ownerId,
            $group,
            fn (): ContentPlacementData => DB::connection((new ContentPlacement)->getConnectionName())
                ->transaction(function () use (
                    $actor,
                    $block,
                    $expectedRevision,
                    $group,
                    $owner,
                    $ownerId,
                    $ownerType,
                    $placement,
                ): ContentPlacementData {
                    $maximum = ContentConfiguration::positiveInteger(
                        'content.placements.maximum_per_group',
                        1_000,
                    );
                    $placements = ContentPlacement::query()
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)
                        ->where('group', $group)
                        ->orderBy('id')
                        ->limit($maximum + 1)
                        ->lockForUpdate()
                        ->get();

                    if ($placements->count() > $maximum) {
                        throw new InvalidArgumentException(
                            "Content owner exceeds the {$maximum} placement limit.",
                        );
                    }

                    $model = $placements->firstWhere('id', $placement);

                    if (! $model instanceof ContentPlacement) {
                        throw (new ModelNotFoundException)->setModel(
                            ContentPlacement::class,
                            [$placement],
                        );
                    }

                    $replacement = ContentBlock::query()
                        ->with(['definition', 'translations'])
                        ->lockForUpdate()
                        ->findOrFail($block);
                    $this->authorization->authorize(
                        ContentAbility::Place,
                        $actor,
                        $replacement,
                        $owner,
                        [
                            'group' => $group,
                            'region' => $model->region,
                            'parent_id' => $model->parent_id,
                            'replaces_placement' => true,
                        ],
                    );

                    if ($model->revision !== $expectedRevision) {
                        throw StaleContentException::forRevision(
                            $model->id,
                            $expectedRevision,
                            $model->revision,
                        );
                    }

                    $this->validator->validate(
                        $replacement,
                        $owner,
                        $ownerType,
                        $ownerId,
                        $group,
                        $model->region,
                        $model->parent_id,
                        is_array($model->overrides) ? $model->overrides : [],
                        $actor,
                        $model->id,
                    );
                    $model->forceFill([
                        'content_block_id' => $replacement->id,
                        'revision' => $model->revision + 1,
                    ])->save();
                    ContentPlacementChanged::dispatch(
                        $model->id,
                        ContentPlacementEvent::Updated,
                        $model->revision,
                        $actor,
                        $ownerType,
                        $ownerId,
                        $group,
                        $replacement->id,
                    );

                    return ContentPlacementData::fromModel(
                        $model->refresh()->load(['block.definition', 'block.translations']),
                    );
                }, 3),
        );
    }
}
