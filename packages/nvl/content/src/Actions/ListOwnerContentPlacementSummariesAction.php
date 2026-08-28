<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Lists bounded placement summaries for an authorized collection of Content owners.
 */
final readonly class ListOwnerContentPlacementSummariesAction
{
    private const int MAXIMUM_OWNERS = 100;

    public function __construct(
        private ContentAuthorization $authorization,
        private ContentOwnerRegistry $owners,
        private ContentIdentityGuard $identities,
    ) {}

    /**
     * Return deterministic placement summaries keyed by canonical owner identity.
     *
     * @param  iterable<array-key, Model&ContentOwner>  $owners
     * @return array<string, list<ContentPlacementData>>
     */
    public function execute(
        iterable $owners,
        string $group,
        ContentActorData $actor,
    ): array {
        $normalized = $this->normalize($owners, $group);

        if ($normalized === []) {
            return [];
        }

        foreach ($normalized as $identity) {
            $this->authorization->authorize(
                ContentAbility::ListPlacements,
                $actor,
                owner: $identity['owner'],
                context: [
                    'group' => $group,
                    'includes_blocks' => true,
                ],
            );
        }

        $this->assertPersisted($normalized);
        $maximum = ContentConfiguration::positiveInteger(
            'content.placements.maximum_per_group',
            1_000,
        );
        /** @var array<string, list<ContentPlacementData>> $summaries */
        $summaries = [];
        /** @var array<string, list<array{owner: Model&ContentOwner, type: string, id: string, model: class-string<Model&ContentOwner>}>> $ownersByType */
        $ownersByType = [];

        foreach ($normalized as $identity) {
            $summaries[$this->summaryKey($identity['type'], $identity['id'])] = [];
            $ownersByType[$identity['type']][] = $identity;
        }

        foreach ($ownersByType as $type => $identities) {
            $ownerIds = array_map(
                static fn (array $identity): string => $identity['id'],
                $identities,
            );
            $queryLimit = $this->queryLimit($maximum, count($ownerIds));
            $placements = ContentPlacement::query()
                ->select([
                    'id',
                    'content_block_id',
                    'owner_type',
                    'owner_id',
                    'group',
                    'key',
                    'parent_id',
                    'region',
                    'sort_order',
                    'is_visible',
                    'overrides',
                    'revision',
                ])
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
                ->where('owner_type', $type)
                ->whereIn('owner_id', $ownerIds)
                ->where('group', $group)
                ->orderBy('owner_id')
                ->orderBy('region')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit($queryLimit)
                ->get();

            if ($placements->count() === $queryLimit) {
                throw new InvalidArgumentException(
                    "A Content owner exceeds the {$maximum} placement limit.",
                );
            }
            /** @var array<string, list<ContentPlacementData>> $placementsByOwner */
            $placementsByOwner = [];

            foreach ($placements as $placement) {
                $placementsByOwner[$placement->owner_id][] = ContentPlacementData::fromModel(
                    $placement,
                );
            }

            foreach ($ownerIds as $ownerId) {
                $ownerPlacements = $placementsByOwner[$ownerId] ?? [];

                if (count($ownerPlacements) > $maximum) {
                    throw new InvalidArgumentException(
                        "Content owner exceeds the {$maximum} placement limit.",
                    );
                }

                $summaries[$this->summaryKey($type, $ownerId)] = $ownerPlacements;
            }
        }

        return $summaries;
    }

    /**
     * @param  iterable<array-key, Model&ContentOwner>  $owners
     * @return list<array{owner: Model&ContentOwner, type: string, id: string, model: class-string<Model&ContentOwner>}>
     */
    private function normalize(iterable $owners, string $group): array
    {
        $normalized = [];
        $identities = [];
        $entries = 0;

        foreach ($owners as $candidate) {
            $entries++;

            if ($entries > self::MAXIMUM_OWNERS) {
                throw new InvalidArgumentException(
                    'Content placement summaries support at most 100 owner entries.',
                );
            }

            $owner = $this->normalizeOwner($candidate);

            if (! $owner->exists) {
                throw new InvalidArgumentException('A Content owner must be persisted.');
            }

            $type = $this->owners->type($owner);
            $identifier = $owner->getKey();

            if (! is_int($identifier) && ! is_string($identifier)) {
                throw new InvalidArgumentException(
                    'A Content owner identifier must be a string or integer.',
                );
            }

            $id = (string) $identifier;
            $this->identities->owner($type, $id);
            $this->owners->assertGroup($owner, $group);
            $identity = $type.':'.$id;

            if (isset($identities[$identity])) {
                continue;
            }

            $identities[$identity] = true;
            $normalized[] = [
                'owner' => $owner,
                'type' => $type,
                'id' => $id,
                'model' => $this->owners->model($type),
            ];

        }

        return $normalized;
    }

    /**
     * Return the non-numeric serialization-safe key for one canonical owner identity.
     */
    private function summaryKey(string $type, string $id): string
    {
        return $type.':'.$id;
    }

    /**
     * Return one overflow-detecting query limit without risking integer overflow.
     */
    private function queryLimit(int $maximum, int $owners): int
    {
        if ($maximum > intdiv(PHP_INT_MAX - 1, $owners)) {
            throw new InvalidArgumentException(
                'The configured Content placement limit is too large for bulk summaries.',
            );
        }

        return ($maximum * $owners) + 1;
    }

    /**
     * Validate one runtime iterable entry while preserving the public iterable contract.
     */
    private function normalizeOwner(mixed $owner): Model&ContentOwner
    {
        if (! $owner instanceof Model || ! $owner instanceof ContentOwner) {
            throw new InvalidArgumentException(
                'Content placement summary owners must be Eloquent ContentOwner models.',
            );
        }

        return $owner;
    }

    /**
     * @param  list<array{owner: Model&ContentOwner, type: string, id: string, model: class-string<Model&ContentOwner>}>  $owners
     */
    private function assertPersisted(array $owners): void
    {
        /** @var array<class-string<Model&ContentOwner>, list<array{owner: Model&ContentOwner, type: string, id: string, model: class-string<Model&ContentOwner>}>> $ownersByModel */
        $ownersByModel = [];

        foreach ($owners as $identity) {
            $ownersByModel[$identity['model']][] = $identity;
        }

        foreach ($ownersByModel as $modelClass => $identities) {
            /** @var Model&ContentOwner $model */
            $model = new $modelClass;
            $ids = array_map(
                static fn (array $identity): string => $identity['id'],
                $identities,
            );
            $persistedIds = $model->newQuery()
                ->whereKey($ids)
                ->pluck($model->getKeyName())
                ->all();
            /** @var array<string, true> $persisted */
            $persisted = [];

            foreach ($persistedIds as $persistedId) {
                if (! is_int($persistedId) && ! is_string($persistedId)) {
                    throw new InvalidArgumentException(
                        'A persisted Content owner identifier must be a string or integer.',
                    );
                }

                $persisted[(string) $persistedId] = true;
            }

            foreach ($ids as $id) {
                if (! isset($persisted[$id])) {
                    throw new InvalidArgumentException(
                        "Content owner [{$id}] no longer exists.",
                    );
                }
            }
        }
    }
}
