<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Support\SeoModelIdentifier;

/**
 * Resolves explicitly registered owner aliases for HTTP and import boundaries.
 */
final class SeoOwnerRegistry
{
    /**
     * @var array<string, class-string<Model>>|null
     */
    private ?array $owners = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $morphAliases = null;

    /**
     * Resolve one registered alias and model identifier.
     */
    public function resolve(string $alias, string|int $id): Model
    {
        $modelClass = $this->modelClass($alias);
        $id = SeoModelIdentifier::normalize($id);

        /** @var Model $model */
        $model = $modelClass::query()->findOrFail($id);

        return $model;
    }

    /**
     * Return the configured model class for one stable owner alias.
     *
     * @return class-string<Model>
     */
    public function modelClass(string $alias): string
    {
        $owners = $this->configured();
        $modelClass = $owners[$alias] ?? null;

        if ($modelClass === null) {
            throw InvalidSeoMutationException::forField(
                'ownerAlias',
                "SEO owner alias [{$alias}] is not registered with an Eloquent model.",
            );
        }

        return $modelClass;
    }

    /**
     * Return the stable alias for one model instance.
     */
    public function aliasFor(Model $model): string
    {
        return $this->aliasForMorphType($model->getMorphClass());
    }

    /**
     * Return the stable alias for one stored Eloquent morph type.
     */
    public function aliasForMorphType(string $morphType): string
    {
        $this->configured();
        $alias = $this->morphAliases[$morphType] ?? null;

        if ($alias === null) {
            throw InvalidSeoMutationException::because(
                "SEO owner morph type [{$morphType}] has no registered alias.",
            );
        }

        return $alias;
    }

    /**
     * Return the Eloquent morph type represented by one stable owner alias.
     */
    public function morphTypeForAlias(string $alias): string
    {
        $modelClass = $this->modelClass($alias);

        return (new $modelClass)->getMorphClass();
    }

    /**
     * Return every valid alias-to-model registration.
     *
     * @return array<string, class-string<Model>>
     */
    public function configured(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }

        $configured = config('seo.owners', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('seo.owners must be an alias-to-model map.');
        }

        $owners = [];
        $morphAliases = [];

        foreach ($configured as $alias => $modelClass) {
            if (! is_string($alias)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $alias) !== 1
                || ! is_string($modelClass)
                || ! is_a($modelClass, Model::class, true)) {
                throw new InvalidArgumentException(
                    'Every seo.owners entry must use a safe alias and an Eloquent model class.',
                );
            }

            /** @var class-string<Model> $modelClass */
            $owners[$alias] = $modelClass;
            $morphType = (new $modelClass)->getMorphClass();

            if (isset($morphAliases[$morphType])) {
                throw new InvalidArgumentException(
                    "SEO owner aliases [{$morphAliases[$morphType]}] and [{$alias}] "
                    ."both resolve to morph type [{$morphType}].",
                );
            }

            $morphAliases[$morphType] = $alias;
        }

        ksort($owners);
        $this->owners = $owners;
        $this->morphAliases = $morphAliases;

        return $this->owners;
    }
}
