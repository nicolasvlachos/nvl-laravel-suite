<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Pages\Contracts\PageResourceHandler;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Deterministic allowlist of dynamic page-resource handlers.
 */
final class PageResourceRegistry
{
    /** @var array<string, class-string<PageResourceHandler<Model>>> */
    private array $handlers = [];

    /**
     * Create the resource registry with its handler container.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register one stable resource alias and handler class.
     */
    public function register(string $alias, string $handler): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $alias) !== 1) {
            throw new InvalidArgumentException("Page resource alias [{$alias}] is invalid.");
        }

        if (isset($this->handlers[$alias])) {
            throw new InvalidArgumentException("Page resource [{$alias}] is already registered.");
        }

        if (! is_a($handler, PageResourceHandler::class, true)) {
            throw new InvalidArgumentException(
                "Page resource handler [{$handler}] must implement PageResourceHandler.",
            );
        }

        $this->handlers[$alias] = $handler;
        ksort($this->handlers);
        $this->validate($this->resolve($alias), $alias);
    }

    /**
     * Return one registered resource handler.
     *
     * @return PageResourceHandler<Model>
     */
    public function get(string $alias): PageResourceHandler
    {
        $handler = $this->resolve($alias);
        $this->validate($handler, $alias);

        return $handler;
    }

    /**
     * Determine whether one resource alias is registered.
     */
    public function has(string $alias): bool
    {
        return isset($this->handlers[$alias]);
    }

    /**
     * Return every registered resource alias.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Resolve one registered resource handler from the container.
     *
     * @return PageResourceHandler<Model>
     */
    private function resolve(string $alias): PageResourceHandler
    {
        $class = $this->handlers[$alias]
            ?? throw new InvalidArgumentException("Page resource [{$alias}] is not registered.");
        $handler = $this->container->make($class);

        if (! $handler instanceof PageResourceHandler) {
            throw new InvalidArgumentException("Page resource handler [{$class}] is invalid.");
        }

        return $handler;
    }

    /**
     * Validate a resolved resource handler against its registered alias and route pattern.
     *
     * @param  PageResourceHandler<Model>  $handler
     */
    private function validate(PageResourceHandler $handler, string $alias): void
    {
        if ($handler->alias() !== $alias) {
            throw new InvalidArgumentException(
                'Page resource handler ['.$handler::class
                ."] does not provide alias [{$alias}].",
            );
        }

        $pattern = trim($handler->routePattern(), '/');

        if ($pattern === ''
            || str_contains($pattern, '..')
            || str_contains($pattern, '//')
            || str_contains($pattern, '\\')) {
            throw new InvalidArgumentException(
                "Page resource [{$alias}] route pattern is invalid.",
            );
        }

        $parameters = [];

        foreach (explode('/', $pattern) as $segment) {
            if (preg_match('/^\\{([a-z][a-zA-Z0-9_]*)\\}$/D', $segment, $matches) === 1) {
                if (in_array($matches[1], $parameters, true)) {
                    throw new InvalidArgumentException(
                        "Page resource [{$alias}] repeats a route parameter.",
                    );
                }

                $parameters[] = $matches[1];

                continue;
            }

            if (preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,189}[a-z0-9])?$/D', $segment) !== 1) {
                throw new InvalidArgumentException(
                    "Page resource [{$alias}] route pattern contains an invalid segment.",
                );
            }
        }

        if ($parameters === []
            || count($parameters) > PagesConfiguration::limit('maximum_resource_parameters', 8)) {
            throw new InvalidArgumentException(
                "Page resource [{$alias}] must declare a bounded route parameter list.",
            );
        }
    }
}
