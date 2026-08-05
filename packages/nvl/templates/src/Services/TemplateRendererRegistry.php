<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplateRenderer;

/**
 * Resolves allowlisted renderer aliases through the Laravel container.
 */
final class TemplateRendererRegistry
{
    /** @var array<string, class-string<TemplateRenderer>> */
    private array $renderers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register one renderer alias and implementation class.
     *
     * @param  class-string  $renderer
     */
    public function register(string $alias, string $renderer): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $alias) !== 1) {
            throw new InvalidArgumentException("Template renderer alias [{$alias}] is invalid.");
        }

        if (isset($this->renderers[$alias])) {
            throw new InvalidArgumentException("Template renderer [{$alias}] is already registered.");
        }

        if (! is_a($renderer, TemplateRenderer::class, true)) {
            throw new InvalidArgumentException(
                "Template renderer [{$renderer}] must implement TemplateRenderer.",
            );
        }

        $this->renderers[$alias] = $renderer;
        ksort($this->renderers);
    }

    /**
     * Determine whether one renderer alias is registered.
     */
    public function has(string $alias): bool
    {
        return isset($this->renderers[$alias]);
    }

    /**
     * Resolve one registered renderer through the container.
     */
    public function get(string $alias): TemplateRenderer
    {
        $class = $this->renderers[$alias]
            ?? throw new InvalidArgumentException("Template renderer [{$alias}] is not registered.");
        $renderer = $this->container->make($class);

        if (! $renderer instanceof TemplateRenderer) {
            throw new InvalidArgumentException("Template renderer [{$class}] could not be resolved.");
        }

        return $renderer;
    }

    /**
     * Return every registered renderer in deterministic alias order.
     *
     * @return array<string, class-string<TemplateRenderer>>
     */
    public function all(): array
    {
        return $this->renderers;
    }
}
