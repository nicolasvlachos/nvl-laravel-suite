<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplateOwnerResolver;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/**
 * Allowlist for assignment owner aliases exposed to routes and imports.
 */
final class TemplateOwnerRegistry
{
    /** @var array<string, class-string<TemplateOwnerResolver>> */
    private array $resolvers = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly TenantBoundary $boundary,
        private readonly TenantResourceRegistry $resources,
    ) {}

    public function register(string $alias, string $resolver): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $alias) !== 1) {
            throw new InvalidArgumentException("Template owner alias [{$alias}] is invalid.");
        }

        if (isset($this->resolvers[$alias])) {
            throw new InvalidArgumentException("Template owner [{$alias}] is already registered.");
        }

        if (! is_a($resolver, TemplateOwnerResolver::class, true)) {
            throw new InvalidArgumentException(
                "Template owner resolver [{$resolver}] must implement TemplateOwnerResolver.",
            );
        }

        $this->resolvers[$alias] = $resolver;
        ksort($this->resolvers);
    }

    public function resolve(string $alias, string $identifier): Model
    {
        $class = $this->resolvers[$alias]
            ?? throw new InvalidArgumentException("Template owner [{$alias}] is not registered.");
        $resolver = $this->container->make($class);

        if (! $resolver instanceof TemplateOwnerResolver || $resolver->alias() !== $alias) {
            throw new InvalidArgumentException("Template owner resolver [{$class}] is invalid.");
        }

        $owner = $resolver->resolve($identifier)
            ?? throw new InvalidArgumentException(
                "Template owner [{$alias}:{$identifier}] does not exist.",
            );

        if ($this->config->get('tenancy.enabled') === true) {
            $this->boundary->assertRecord(
                $owner,
                $this->resources->forModel($owner)->key,
            );
        }

        return $owner;
    }

    public function has(string $alias): bool
    {
        return isset($this->resolvers[$alias]);
    }

    /**
     * Return registered aliases without exposing consumer model classes.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->resolvers);
    }
}
