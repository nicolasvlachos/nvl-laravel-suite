<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\UpdateContentPlacementData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentPlacementOwnerLock;
use Nvl\Content\Services\ContentPlacementValidator;

/**
 * Reparents, reorders, and overrides one placement with cycle and revision checks.
 */
final readonly class UpdateContentPlacementAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentPlacementValidator $validator,
        private ContentIdentityGuard $identities,
        private ContentPlacementOwnerLock $ownerLocks,
    ) {}

    /**
     * Update one placement while preserving its owner and group identity.
     */
    public function execute(
        ContentPlacement|string $placement,
        UpdateContentPlacementData $data,
        ContentActorData $actor,
    ): ContentPlacement {
        $placementId = $placement instanceof ContentPlacement ? $placement->id : $placement;
        $this->identities->region($data->region);
        $this->identities->sortOrder($data->sortOrder);
        $identity = ContentPlacement::query()
            ->findOrFail($placementId, ['owner_type', 'owner_id', 'group']);

        return $this->ownerLocks->run(
            $identity->owner_type,
            $identity->owner_id,
            $identity->group,
            fn (): ContentPlacement => DB::connection((new ContentPlacement)->getConnectionName())
                ->transaction(function () use ($actor, $data, $placementId): ContentPlacement {
                    $model = ContentPlacement::query()
                        ->with(['block.definition', 'block.translations'])
                        ->lockForUpdate()
                        ->findOrFail($placementId);
                    $owner = $this->owners->resolve($model->owner_type, $model->owner_id);
                    $this->owners->assertGroup($owner, $model->group);
                    $this->authorization->authorize(
                        ContentAbility::Place,
                        $actor,
                        $model->block,
                        $owner,
                        [
                            'group' => $model->group,
                            'region' => $data->region,
                            'parent_id' => $data->parentId,
                        ],
                    );

                    if ($model->revision !== $data->expectedRevision) {
                        throw StaleContentException::forRevision(
                            $model->id,
                            $data->expectedRevision,
                            $model->revision,
                        );
                    }

                    ContentPlacement::query()
                        ->where('owner_type', $model->owner_type)
                        ->where('owner_id', $model->owner_id)
                        ->where('group', $model->group)
                        ->lockForUpdate()
                        ->get(['id']);
                    $overrides = $this->validator->validate(
                        $model->block,
                        $owner,
                        $model->owner_type,
                        $model->owner_id,
                        $model->group,
                        $data->region,
                        $data->parentId,
                        $data->overrides,
                        $actor,
                        $model->id,
                    );
                    $model->forceFill([
                        'parent_id' => $data->parentId,
                        'region' => $data->region,
                        'sort_order' => $data->sortOrder,
                        'is_visible' => $data->isVisible,
                        'overrides' => $overrides === [] ? null : $overrides,
                        'revision' => $model->revision + 1,
                    ])->save();
                    ContentPlacementChanged::dispatch(
                        $model->id,
                        ContentPlacementEvent::Updated,
                        $model->revision,
                        $actor,
                        $model->owner_type,
                        $model->owner_id,
                        $model->group,
                        $model->content_block_id,
                    );

                    return $model->refresh();
                }, 3),
        );
    }
}
