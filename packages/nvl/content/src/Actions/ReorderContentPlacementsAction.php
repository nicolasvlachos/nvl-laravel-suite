<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentEditorData;
use Nvl\Content\Data\Mutations\ReorderContentPlacementData;
use Nvl\Content\Data\Mutations\ReorderContentPlacementsData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentPlacementOwnerLock;
use Nvl\Content\Services\ContentPlacementTree;
use Nvl\Content\Services\ContentPlacementValidator;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Applies one complete owner-group tree proposal under the canonical mutation lock.
 *
 * Delegation to GetOwnerContentEditorAction is deliberate orchestration so the
 * committed mutation returns the same authorized projection as every editor read.
 */
final readonly class ReorderContentPlacementsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentIdentityGuard $identities,
        private ContentPlacementOwnerLock $ownerLocks,
        private ContentPlacementTree $tree,
        private ContentPlacementValidator $validator,
        private GetOwnerContentEditorAction $getOwnerEditor,
    ) {}

    /**
     * Apply one complete revision-safe tree proposal and return the fresh editor.
     */
    public function execute(
        Model&ContentOwner $owner,
        string $group,
        ReorderContentPlacementsData $data,
        ContentActorData $actor,
    ): ContentEditorData {
        $maximum = ContentConfiguration::positiveInteger(
            'content.placements.maximum_per_group',
            1_000,
        );
        $proposal = $this->normalizeProposal($data, $maximum);
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $this->owners->assertGroup($owner, $group);

        return $this->ownerLocks->run(
            $ownerType,
            $ownerId,
            $group,
            fn (): ContentEditorData => DB::connection((new ContentPlacement)->getConnectionName())
                ->transaction(function () use (
                    $actor,
                    $group,
                    $maximum,
                    $owner,
                    $ownerId,
                    $ownerType,
                    $proposal,
                ): ContentEditorData {
                    /** @var Collection<int, ContentPlacement> $placements */
                    $placements = ContentPlacement::query()
                        ->with(['block.definition', 'block.translations'])
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

                    $placementIds = $placements->pluck('id')->all();
                    $proposalIds = array_keys($proposal);
                    sort($proposalIds);

                    if ($placementIds !== $proposalIds) {
                        throw new InvalidArgumentException(
                            'A Content reorder must contain exactly one item for every owner-group placement.',
                        );
                    }

                    foreach ($placements as $placement) {
                        $item = $proposal[$placement->id];
                        $block = $placement->getRelation('block');

                        if (! $block instanceof ContentBlock) {
                            throw new InvalidArgumentException(
                                "Content placement [{$placement->id}] references a missing block.",
                            );
                        }

                        $this->authorization->authorize(
                            ContentAbility::Place,
                            $actor,
                            $block,
                            $owner,
                            [
                                'group' => $group,
                                'region' => $item->region,
                                'parent_id' => $item->parentId,
                                'reorders_placements' => true,
                            ],
                        );
                    }

                    foreach ($placements as $placement) {
                        $expected = $proposal[$placement->id]->expectedRevision;

                        if ($placement->revision !== $expected) {
                            throw StaleContentException::forRevision(
                                $placement->id,
                                $expected,
                                $placement->revision,
                            );
                        }
                    }

                    foreach ($placements as $placement) {
                        $item = $proposal[$placement->id];
                        $block = $placement->getRelation('block');

                        if (! $block instanceof ContentBlock) {
                            throw new InvalidArgumentException(
                                "Content placement [{$placement->id}] references a missing block.",
                            );
                        }

                        $this->validator->validateDefinition(
                            $block,
                            $owner,
                            $group,
                            $item->region,
                            is_array($placement->overrides) ? $placement->overrides : [],
                            $actor,
                        );
                    }

                    foreach ($placements as $placement) {
                        $item = $proposal[$placement->id];
                        $placement->forceFill([
                            'region' => $item->region,
                            'parent_id' => $item->parentId,
                            'sort_order' => $item->sortOrder,
                        ]);
                    }

                    $this->tree->assertValidProposal(
                        $placements,
                        $ownerType,
                        $ownerId,
                        $group,
                    );
                    $changed = $placements->filter(
                        static fn (ContentPlacement $placement): bool => $placement->isDirty([
                            'region',
                            'parent_id',
                            'sort_order',
                        ]),
                    );

                    foreach ($changed as $placement) {
                        $placement->forceFill([
                            'revision' => $placement->revision + 1,
                        ])->save();
                    }

                    foreach ($changed as $placement) {
                        ContentPlacementChanged::dispatch(
                            $placement->id,
                            ContentPlacementEvent::Updated,
                            $placement->revision,
                            $actor,
                            $ownerType,
                            $ownerId,
                            $group,
                            $placement->content_block_id,
                        );
                    }

                    return $this->getOwnerEditor->execute($owner, $group, $actor);
                }, 3),
        );
    }

    /**
     * @return array<string, ReorderContentPlacementData>
     */
    private function normalizeProposal(
        ReorderContentPlacementsData $data,
        int $maximum,
    ): array {
        if (count($data->placements) > $maximum) {
            throw new InvalidArgumentException(
                "A Content reorder supports at most {$maximum} placements.",
            );
        }

        $proposal = [];

        foreach ($data->placements as $item) {
            if (! Str::isUuid($item->id)
                || ($item->parentId !== null && ! Str::isUuid($item->parentId))) {
                throw new InvalidArgumentException(
                    'Content reorder placement and parent identifiers must be UUIDs.',
                );
            }

            $this->identities->region($item->region);
            $this->identities->sortOrder($item->sortOrder);

            if (isset($proposal[$item->id])) {
                throw new InvalidArgumentException(
                    "Content reorder contains duplicate placement [{$item->id}].",
                );
            }

            $proposal[$item->id] = $item;
        }

        ksort($proposal);

        return $proposal;
    }
}
