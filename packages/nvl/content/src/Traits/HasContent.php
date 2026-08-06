<?php

declare(strict_types=1);

namespace Nvl\Content\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Relations\StringMorphMany;

/**
 * Adds the direct polymorphic Content relationship and owner lifecycle cleanup.
 *
 * @mixin Model
 */
trait HasContent
{
    /**
     * Return the owner’s declared composition groups.
     *
     * Define CONTENT_GROUPS for multiple groups or CONTENT_GROUP for one group.
     *
     * @return list<string>
     */
    public function contentGroups(): array
    {
        if (defined(static::class.'::CONTENT_GROUPS')) {
            return self::validatedContentGroups(
                constant(static::class.'::CONTENT_GROUPS'),
            );
        }

        if (defined(static::class.'::CONTENT_GROUP')) {
            return [self::validatedContentGroup(
                constant(static::class.'::CONTENT_GROUP'),
            )];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function validatedContentGroups(mixed $groups): array
    {
        if (! is_array($groups) || ! array_is_list($groups)) {
            throw new InvalidArgumentException(
                'CONTENT_GROUPS must be a list of composition group strings.',
            );
        }

        $validated = [];

        foreach ($groups as $group) {
            $validated[] = self::validatedContentGroup($group);
        }

        return $validated;
    }

    private static function validatedContentGroup(mixed $group): string
    {
        if (! is_string($group)) {
            throw new InvalidArgumentException(
                'Content composition groups must be strings.',
            );
        }

        return $group;
    }

    /**
     * Boot owner cleanup without removing placements for reversible soft deletes.
     */
    public static function bootHasContent(): void
    {
        static::deleting(static function (Model $owner): void {
            if (method_exists($owner, 'isForceDeleting')
                && $owner->isForceDeleting() !== true) {
                return;
            }

            if (! $owner instanceof ContentOwner) {
                return;
            }

            $owner->contentPlacements()->delete();
        });
    }

    /**
     * Return every Content placement directly associated with this owner.
     *
     * @return MorphMany<ContentPlacement, $this>
     */
    public function contentPlacements(): MorphMany
    {
        $related = new ContentPlacement;

        return new StringMorphMany(
            $related->newQuery(),
            $this,
            $related->qualifyColumn('owner_type'),
            $related->qualifyColumn('owner_id'),
            $this->getKeyName(),
        );
    }
}
