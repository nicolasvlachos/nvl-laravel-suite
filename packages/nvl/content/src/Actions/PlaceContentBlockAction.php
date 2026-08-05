<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentPlacementOwnerLock;
use Nvl\Content\Services\ContentPlacementValidator;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Places one reusable block in an allowlisted owner tree.
 */
final readonly class PlaceContentBlockAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentPlacementValidator $validator,
        private ContentIdentityGuard $identities,
        private ContentPlacementOwnerLock $ownerLocks,
    ) {}

    /**
     * Place one block in a validated, revisioned owner group tree.
     */
    public function execute(
        ContentBlock|string $block,
        Model&ContentOwner $owner,
        string $group,
        PlaceContentBlockData $data,
        ContentActorData $actor,
    ): ContentPlacement {
        $model = $block instanceof ContentBlock
            ? $block->loadMissing(['definition', 'translations'])
            : ContentBlock::query()->with(['definition', 'translations'])->findOrFail($block);
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $this->owners->assertGroup($owner, $group);
        $this->identities->placementKey($data->key);
        $this->identities->region($data->region);
        $this->identities->sortOrder($data->sortOrder);

        return $this->ownerLocks->run(
            $ownerType,
            $ownerId,
            $group,
            fn (): ContentPlacement => DB::connection($model->getConnectionName())
                ->transaction(function () use (
                    $actor,
                    $data,
                    $group,
                    $model,
                    $owner,
                    $ownerId,
                    $ownerType,
                ): ContentPlacement {
                    $lockedBlock = ContentBlock::query()
                        ->with(['definition', 'translations'])
                        ->lockForUpdate()
                        ->findOrFail($model->id);
                    $this->authorization->authorize(
                        ContentAbility::Place,
                        $actor,
                        $lockedBlock,
                        $owner,
                        [
                            'group' => $group,
                            'region' => $data->region,
                            'parent_id' => $data->parentId,
                        ],
                    );
                    $maximum = ContentConfiguration::positiveInteger(
                        'content.placements.maximum_per_group',
                        1_000,
                    );
                    $locked = ContentPlacement::query()
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)
                        ->where('group', $group)
                        ->limit($maximum + 1)
                        ->lockForUpdate()
                        ->get(['id']);

                    if ($locked->count() >= $maximum) {
                        throw new InvalidArgumentException(
                            "Content owner already has the maximum {$maximum} placements.",
                        );
                    }

                    $overrides = $this->validator->validate(
                        $lockedBlock,
                        $owner,
                        $ownerType,
                        $ownerId,
                        $group,
                        $data->region,
                        $data->parentId,
                        $data->overrides,
                        $actor,
                    );
                    $placement = ContentPlacement::query()->create([
                        'content_block_id' => $lockedBlock->id,
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                        'group' => $group,
                        'key' => $data->key,
                        'parent_id' => $data->parentId,
                        'region' => $data->region,
                        'sort_order' => $data->sortOrder,
                        'is_visible' => $data->isVisible,
                        'overrides' => $overrides === [] ? null : $overrides,
                    ]);
                    ContentPlacementChanged::dispatch(
                        $placement->id,
                        ContentPlacementEvent::Created,
                        $placement->revision,
                        $actor,
                        $ownerType,
                        $ownerId,
                        $group,
                        $lockedBlock->id,
                    );

                    return $placement;
                }, 3),
        );
    }
}
