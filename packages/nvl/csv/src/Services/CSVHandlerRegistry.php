<?php

declare(strict_types=1);

namespace Nvl\Csv\Services;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nvl\Csv\Contracts\CSVRowHandler;

/** Retains only stable aliases and class names; handlers resolve in restored context. */
final class CSVHandlerRegistry
{
    /** @var array<string,class-string<CSVRowHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $alias, string $handlerClass): void
    {
        $alias = trim($alias);
        if ($alias === '' || ! is_a($handlerClass, CSVRowHandler::class, true)) {
            throw new InvalidArgumentException('CSV handlers require a non-empty alias and contract class.');
        }
        if (isset($this->handlers[$alias]) && $this->handlers[$alias] !== $handlerClass) {
            throw new InvalidArgumentException("CSV handler alias [{$alias}] is already registered.");
        }
        $this->handlers[$alias] = $handlerClass;
    }

    public function resolve(string $alias): CSVRowHandler
    {
        $class = $this->handlers[$alias] ?? throw new InvalidArgumentException("CSV handler [{$alias}] is not registered.");
        $handler = $this->container->build($class);
        if (! $handler instanceof CSVRowHandler) {
            throw new InvalidArgumentException("CSV handler [{$alias}] resolved incorrectly.");
        }

        return $handler;
    }

    public function has(string $alias): bool
    {
        return isset($this->handlers[$alias]);
    }
}
