<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentEditorData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Builds the complete bounded projection for one authorized Content editor.
 *
 * Delegation to the catalog and placement Actions is deliberate orchestration
 * so their authorization, ordering, and row-bound contracts remain canonical.
 */
final readonly class GetOwnerContentEditorAction
{
    public function __construct(
        private ListContentDefinitionsAction $listDefinitions,
        private ListContentPresetsAction $listPresets,
        private ListContentGroupsAction $listGroups,
        private ListContentPlacementsAction $listPlacements,
        private ContentOwnerRegistry $owners,
    ) {}

    /**
     * Return the complete typed bootstrap payload for one consumer-owned editor.
     */
    public function execute(
        Model&ContentOwner $owner,
        string $group,
        ContentActorData $actor,
    ): ContentEditorData {
        $definitions = array_values($this->listDefinitions->execute($actor)->all());
        $presets = array_values($this->listPresets->execute($actor)->all());
        $groups = array_values($this->listGroups->execute($owner, $actor)->all());
        $placements = $this->listPlacements->execute(
            $owner,
            $group,
            $actor,
            includeBlocks: true,
        );

        return new ContentEditorData(
            ownerType: $this->owners->type($owner),
            ownerId: $this->owners->id($owner),
            group: $group,
            definitions: $definitions,
            presets: $presets,
            groups: $groups,
            placements: array_values($placements
                ->map(ContentPlacementData::fromModel(...))
                ->all()),
            placementLimit: ContentConfiguration::positiveInteger(
                'content.placements.maximum_per_group',
                1_000,
            ),
        );
    }
}
