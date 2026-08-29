<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Relations\ExactTextValueComparison;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentOwnerRegistry;

/**
 * Finds one unambiguous placement inside an authorized owner composition.
 */
final readonly class FindContentPlacementAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentIdentityGuard $identities,
    ) {}

    /**
     * Return one editable placement by exact ID or key inside its owner group.
     */
    public function execute(
        Model&ContentOwner $owner,
        string $group,
        string $idOrKey,
        ContentActorData $actor,
    ): ContentPlacementData {
        $ownerType = $this->owners->type($owner);
        $ownerId = $this->owners->id($owner);
        $this->owners->assertGroup($owner, $group);
        $this->identities->placementKey($idOrKey);
        $this->authorization->authorize(
            ContentAbility::ListPlacements,
            $actor,
            owner: $owner,
            context: [
                'group' => $group,
                'includes_blocks' => true,
            ],
        );
        $query = ContentPlacement::query()
            ->with([
                'block' => static function (Relation $query): void {
                    $query->select([
                        'id',
                        'definition_id',
                        'key',
                        'scope',
                        'scope_key',
                        'status',
                        'visibility',
                        'values',
                        'metadata',
                        'definition_version',
                        'revision',
                        'published_at',
                    ]);
                },
                'block.definition' => static function (Relation $query): void {
                    $query->select(['id', 'key']);
                },
                'block.translations' => static function (Relation $query): void {
                    $query->select(['id', 'content_block_id', 'locale', 'values']);
                },
            ])
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('group', $group);
        $driver = $query->getModel()->getConnection()->getDriverName();
        $wrappedKey = $query->getQuery()->getGrammar()->wrap(
            $query->qualifyColumn('key'),
        );
        $exactKey = new ExactTextValueComparison($wrappedKey, $driver);

        if (Str::isUuid($idOrKey)) {
            $query->where(static function (Builder $query) use ($exactKey, $idOrKey): void {
                $query
                    ->whereKey($idOrKey)
                    ->orWhere(static function (Builder $query) use ($exactKey, $idOrKey): void {
                        $query->whereRaw($exactKey, [$idOrKey, $idOrKey]);
                    });
            });
        } else {
            $query->whereRaw($exactKey, [$idOrKey, $idOrKey]);
        }

        $placements = $query
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($placements->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(ContentPlacement::class, [$idOrKey]);
        }

        if ($placements->count() !== 1) {
            throw new InvalidArgumentException(
                "Content placement identity [{$idOrKey}] is ambiguous in the owner group.",
            );
        }

        return ContentPlacementData::fromModel($placements->firstOrFail());
    }
}
