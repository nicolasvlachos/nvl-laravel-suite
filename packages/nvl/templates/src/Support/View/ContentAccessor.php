<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\View;

use ArrayAccess;
use LogicException;
use Nvl\Templates\Templates\BaseTemplate;

/**
 * Read-only view adapter over a Content composition.
 *
 * @implements ArrayAccess<array-key, mixed>
 */
final readonly class ContentAccessor implements ArrayAccess
{
    public function __construct(private BaseTemplate $template) {}

    public function get(string $path, mixed $default = null): mixed
    {
        return $this->template->getContent($path, $default);
    }

    public function getFrom(
        string $namespace,
        string $path,
        mixed $default = null,
    ): mixed {
        return $this->template->getContentFromNamespace($namespace, $path, $default);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->template->hasContent($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->template->getContent($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Template content accessors are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Template content accessors are read-only.');
    }
}
