<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentReferenceResolver;
use Nvl\Content\Contracts\TenantSafeContentReferenceResolver;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/**
 * Allowlist for schema-declared references.
 */
final class ContentReferenceRegistry
{
    /** @var array<string, class-string<ContentReferenceResolver>> */
    private array $resolvers = [];

    public function __construct(
        private readonly Container $container,
        private readonly ContentPayloadGuard $guard,
        private readonly Repository $configuration,
        private readonly TenantBoundary $tenancy,
        private readonly TenantResourceRegistry $tenantResources,
    ) {}

    public function register(string $alias, string $resolver): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException("Content reference alias [{$alias}] is invalid.");
        }

        if (isset($this->resolvers[$alias])) {
            throw new InvalidArgumentException("Content reference [{$alias}] is already registered.");
        }

        if (! is_a($resolver, ContentReferenceResolver::class, true)) {
            throw new InvalidArgumentException(
                "Content reference resolver [{$resolver}] must implement ContentReferenceResolver.",
            );
        }

        $this->resolvers[$alias] = $resolver;
        ksort($this->resolvers);
    }

    public function has(string $alias): bool
    {
        return isset($this->resolvers[$alias]);
    }

    public function assertRegistered(string $alias): void
    {
        if (! $this->has($alias)) {
            throw new InvalidArgumentException(
                "Content reference type [{$alias}] is not registered.",
            );
        }
    }

    public function assertExists(
        string $alias,
        string $identifier,
        ContentValidationContext $context,
    ): void {
        $resolver = $this->resolver($alias);
        $this->assertTenantRecord($resolver, $identifier, $context);

        if (! $resolver->exists($identifier, $context)) {
            throw new InvalidArgumentException(
                "Content reference [{$alias}:{$identifier}] does not exist or is unavailable.",
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function display(
        string $alias,
        string $identifier,
        ContentValidationContext $context,
    ): ?array {
        $resolver = $this->resolver($alias);
        $this->assertTenantRecord($resolver, $identifier, $context);
        $display = $resolver->display($identifier, $context);

        if ($display !== null) {
            $this->guard->referenceDisplay($display);
        }

        return $display;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->resolvers);
    }

    private function resolver(string $alias): ContentReferenceResolver
    {
        $this->assertRegistered($alias);
        $class = $this->resolvers[$alias]
            ?? throw new InvalidArgumentException("Content reference type [{$alias}] is unavailable.");
        $resolver = $this->container->make($class);

        if (! $resolver instanceof ContentReferenceResolver || $resolver->alias() !== $alias) {
            throw new InvalidArgumentException("Content reference resolver [{$class}] is invalid.");
        }

        return $resolver;
    }

    /** Deny legacy or foreign dynamic resolvers before existence/display output is consumed. */
    private function assertTenantRecord(
        ContentReferenceResolver $resolver,
        string $identifier,
        ContentValidationContext $context,
    ): void {
        if ($this->configuration->get('tenancy.enabled') !== true) {
            return;
        }
        if (! $resolver instanceof TenantSafeContentReferenceResolver) {
            throw new TenantBoundaryViolation('A tenant Content reference resolver must expose its canonical record.');
        }
        $record = $resolver->tenantRecord($identifier, $context);
        if (! $record instanceof Model) {
            throw new TenantBoundaryViolation('A Content reference is unavailable in the active tenant.');
        }
        $resource = $this->tenantResources->forModel($record);
        $this->tenancy->assertRecord($record, $resource->key);
    }
}
