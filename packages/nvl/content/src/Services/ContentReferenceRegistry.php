<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentReferenceResolver;
use Nvl\Content\Validation\ContentValidationContext;

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
        $display = $this->resolver($alias)->display($identifier, $context);

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
}
