<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Models\ContentPlacement;
use Throwable;

/**
 * Coordinates owner hard deletion with atomic placement and Media-association cleanup.
 */
final readonly class ContentOwnerDeletion
{
    public function __construct(
        private ContentOwnerRegistry $owners,
        private ContentIdentityGuard $identities,
        private ContentPlacementOwnerLock $locks,
        private ContentMediaSynchronizer $media,
    ) {}

    /**
     * Delete an owner and its Content usages under every declared composition lock.
     *
     * @param  Closure(): (bool|null)  $delete
     */
    public function execute(Model&ContentOwner $owner, Closure $delete): ?bool
    {
        try {
            $ownerType = $this->owners->type($owner);
        } catch (InvalidArgumentException $exception) {
            if (! $owner->contentPlacements()->exists()) {
                return $delete();
            }

            throw $exception;
        }
        $ownerId = $this->owners->id($owner);
        $this->identities->owner($ownerType, $ownerId);
        $groups = $this->owners->groups($owner);

        foreach ($owner->contentPlacements()->distinct()->pluck('group') as $group) {
            if (! is_string($group)) {
                throw new InvalidArgumentException('Persisted Content placement groups must be strings.');
            }

            $this->identities->group($group);
            $groups[] = $group;
        }

        $groups = array_values(array_unique($groups));
        sort($groups);

        return $this->withLocks($ownerType, $ownerId, $groups, function () use ($owner, $delete): ?bool {
            $connection = $owner->getConnection();
            $contentConnection = (new ContentPlacement)->getConnection();

            if ($connection->getName() !== $contentConnection->getName()) {
                if ($owner->contentPlacements()->exists()) {
                    throw new InvalidArgumentException(
                        'Content owners with placements must be deleted on the same named database connection as Content.',
                    );
                }

                return $delete();
            }

            $existed = $owner->exists;

            try {
                return $connection->transaction(function () use ($owner, $delete): ?bool {
                    $owner->newQuery()
                        ->withoutGlobalScope(SoftDeletingScope::class)
                        ->whereKey($owner->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $placements = $owner->contentPlacements()->orderBy('id')->lockForUpdate()->get();
                    $deleted = $delete();

                    if ($deleted !== true) {
                        return $deleted;
                    }

                    foreach ($placements as $placement) {
                        $this->media->detachAll($placement);

                        if ($placement->delete() !== true) {
                            throw new InvalidArgumentException('Content placement deletion was canceled.');
                        }
                    }

                    return true;
                });
            } catch (Throwable $exception) {
                $owner->exists = $existed;

                throw $exception;
            }
        });
    }

    /**
     * Acquire owner group locks in deterministic order before deleting any rows.
     *
     * @param  list<string>  $groups
     * @param  Closure(): (bool|null)  $callback
     */
    private function withLocks(string $ownerType, string $ownerId, array $groups, Closure $callback): ?bool
    {
        $group = array_shift($groups);

        return $group === null
            ? $callback()
            : $this->locks->run(
                $ownerType,
                $ownerId,
                $group,
                fn (): ?bool => $this->withLocks($ownerType, $ownerId, $groups, $callback),
            );
    }
}
