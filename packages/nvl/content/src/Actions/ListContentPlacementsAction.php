<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Lists every placement fact for one authorized headless editor owner.
 */
final readonly class ListContentPlacementsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
    ) {}

    /**
     * Return every placement fact in one authorized owner group.
     *
     * @return Collection<int, ContentPlacement>
     */
    public function execute(
        Model&ContentOwner $owner,
        string $group,
        ContentActorData $actor,
    ): Collection {
        $this->owners->assertGroup($owner, $group);
        $this->authorization->authorize(
            ContentAbility::ListPlacements,
            $actor,
            owner: $owner,
            context: ['group' => $group],
        );
        $maximum = ContentConfiguration::positiveInteger(
            'content.placements.maximum_per_group',
            1_000,
        );
        /** @var Collection<int, ContentPlacement> $placements */
        $placements = $owner->contentPlacements()
            ->where('group', $group)
            ->orderBy('region')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($maximum + 1)
            ->get();

        if ($placements->count() > $maximum) {
            throw new InvalidArgumentException(
                "Content owner exceeds the {$maximum} placement limit.",
            );
        }

        return $placements;
    }
}
