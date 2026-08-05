<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentFieldTypeAdapter;

/**
 * Deterministic registry for built-in and application-provided field types.
 */
final class ContentFieldTypeRegistry
{
    /** @var array<string, ContentFieldTypeAdapter> */
    private array $adapters = [];

    public function register(ContentFieldTypeAdapter $adapter): void
    {
        $alias = $adapter->alias();

        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
            throw new InvalidArgumentException("Content field type alias [{$alias}] is invalid.");
        }

        if (isset($this->adapters[$alias])) {
            throw new InvalidArgumentException("Content field type [{$alias}] is already registered.");
        }

        $this->adapters[$alias] = $adapter;
        ksort($this->adapters);
    }

    public function get(string $alias): ContentFieldTypeAdapter
    {
        return $this->adapters[$alias]
            ?? throw new InvalidArgumentException(
                "Content field type [{$alias}] is not registered.",
            );
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->adapters);
    }
}
