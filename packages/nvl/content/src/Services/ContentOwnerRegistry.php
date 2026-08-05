<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Contracts\ContentOwnerRegistrar;

/**
 * Allowlist and morph map for model-backed Content owners.
 */
final class ContentOwnerRegistry implements ContentOwnerRegistrar
{
    /** @var array<string, class-string<Model&ContentOwner>> */
    private array $models = [];

    public function __construct(private readonly ContentIdentityGuard $identities) {}

    /**
     * Register one stable owner alias and its Content-capable Eloquent model.
     *
     * @param  class-string  $model
     */
    public function register(string $alias, string $model): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException("Content owner alias [{$alias}] is invalid.");
        }

        if (isset($this->models[$alias])) {
            throw new InvalidArgumentException("Content owner [{$alias}] is already registered.");
        }

        if (! is_a($model, Model::class, true)
            || ! is_a($model, ContentOwner::class, true)) {
            throw new InvalidArgumentException(
                "Content owner model [{$model}] must extend Model and implement ContentOwner.",
            );
        }

        /** @var Model&ContentOwner $owner */
        $owner = new $model;
        $this->groups($owner);
        $existingAliasModel = Relation::getMorphedModel($alias);

        if ($existingAliasModel !== null && $existingAliasModel !== $model) {
            throw new InvalidArgumentException(
                "Morph alias [{$alias}] is already assigned to [{$existingAliasModel}].",
            );
        }

        foreach (Relation::morphMap() as $registeredAlias => $registeredModel) {
            if ($registeredModel === $model && $registeredAlias !== $alias) {
                throw new InvalidArgumentException(
                    "Content owner model [{$model}] already uses morph alias [{$registeredAlias}].",
                );
            }
        }

        $this->models[$alias] = $model;
        ksort($this->models);
        Relation::morphMap([$alias => $model], merge: true);
    }

    /**
     * Resolve one allowlisted owner identity to its Eloquent model.
     */
    public function resolve(string $alias, string $identifier): Model&ContentOwner
    {
        $this->identities->owner($alias, $identifier);
        $class = $this->models[$alias]
            ?? throw new InvalidArgumentException("Content owner [{$alias}] is not registered.");
        $owner = (new $class)->newQuery()->find($identifier)
            ?? throw new InvalidArgumentException(
                "Content owner [{$alias}:{$identifier}] does not exist.",
            );

        if (! $owner instanceof ContentOwner) {
            throw new InvalidArgumentException(
                "Resolved Content owner [{$alias}] does not implement ContentOwner.",
            );
        }

        $key = $owner->getKey();

        if ((! is_int($key) && ! is_string($key)) || (string) $key !== $identifier) {
            throw new InvalidArgumentException(
                "Resolved content owner [{$alias}] does not match identifier [{$identifier}].",
            );
        }

        return $owner;
    }

    /**
     * Return the stable registered alias for one persisted owner model.
     */
    public function type(Model&ContentOwner $owner): string
    {
        foreach ($this->models as $alias => $model) {
            if ($owner instanceof $model) {
                return $alias;
            }
        }

        throw new InvalidArgumentException(
            'The supplied model is not a registered Content owner.',
        );
    }

    /**
     * Return and validate the portable persisted identifier for one owner.
     */
    public function id(Model&ContentOwner $owner): string
    {
        if (! $owner->exists) {
            throw new InvalidArgumentException('A Content owner must be persisted.');
        }

        $identifier = $owner->getKey();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException(
                'A Content owner identifier must be a string or integer.',
            );
        }

        $id = (string) $identifier;
        $this->identities->owner($this->type($owner), $id);

        if (! $owner->newQuery()->whereKey($identifier)->exists()) {
            throw new InvalidArgumentException(
                'The supplied Content owner no longer exists.',
            );
        }

        return $id;
    }

    /**
     * Return the Eloquent model registered for one stable owner alias.
     *
     * @return class-string<Model&ContentOwner>
     */
    public function model(string $alias): string
    {
        return $this->models[$alias]
            ?? throw new InvalidArgumentException(
                "Content owner [{$alias}] is not registered.",
            );
    }

    /**
     * Return the registered model for an alias, or null when it is available.
     *
     * @return class-string<Model&ContentOwner>|null
     */
    public function registered(string $alias): ?string
    {
        return $this->models[$alias] ?? null;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->models);
    }

    /**
     * Return the owner’s validated composition groups.
     *
     * @return list<string>
     */
    public function groups(Model&ContentOwner $owner): array
    {
        $groups = $owner->contentGroups();
        $ownerClass = $owner::class;

        if ($groups === []) {
            throw new InvalidArgumentException(
                "Content owner [{$ownerClass}] must declare at least one composition group.",
            );
        }

        foreach ($groups as $group) {
            $this->identities->group($group);
        }

        if (count($groups) !== count(array_unique($groups))) {
            throw new InvalidArgumentException(
                "Content owner [{$ownerClass}] contains duplicate composition groups.",
            );
        }

        sort($groups);

        return $groups;
    }

    /**
     * Assert that one composition group is declared by the owner.
     */
    public function assertGroup(Model&ContentOwner $owner, string $group): void
    {
        $this->identities->group($group);

        if (! in_array($group, $this->groups($owner), true)) {
            $ownerClass = $owner::class;

            throw new InvalidArgumentException(
                "Content group [{$group}] is not declared by owner [{$ownerClass}].",
            );
        }
    }
}
