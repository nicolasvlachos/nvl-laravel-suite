<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Services\ContentOwnerRegistry;

/**
 * Lists the named composition groups currently present on one Content owner.
 */
final readonly class ListContentGroupsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
    ) {}

    /**
     * Return the owner’s existing group keys in deterministic order.
     *
     * @return Collection<int, string>
     */
    public function execute(
        Model&ContentOwner $owner,
        ContentActorData $actor,
    ): Collection {
        $this->authorization->authorize(
            ContentAbility::ListPlacements,
            $actor,
            owner: $owner,
            context: ['groups' => true],
        );

        return collect($this->owners->groups($owner));
    }
}
