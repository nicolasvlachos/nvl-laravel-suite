<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

use Nvl\Forms\Contracts\CustomFormHandler;
use Nvl\Forms\Exceptions\FormException;

/**
 * Registry for mapping form handles to custom submission handlers.
 */
final class FormHandlerRegistry
{
    /**
     * @var array<string, class-string|callable|CustomFormHandler>
     */
    private array $handlers = [];

    /**
     * Register a handler for the given form handle.
     *
     * @param  string  $handle  Form handle identifier
     * @param  class-string|callable|CustomFormHandler  $handler  Handler class or callback
     */
    public function register(string $handle, string|callable|CustomFormHandler $handler): void
    {
        $normalized = trim($handle);
        if ($normalized === '') {
            throw new FormException('Form handler handle must be a non-empty string.');
        }

        if (array_key_exists($normalized, $this->handlers)) {
            throw new FormException("A form handler is already registered for [{$normalized}].");
        }

        $this->handlers[$normalized] = $handler;
    }

    /**
     * Get a handler mapping for the given handle.
     *
     * @param  string  $handle  Form handle identifier
     * @return class-string|callable|CustomFormHandler|null Handler entry
     */
    public function get(string $handle): string|callable|CustomFormHandler|null
    {
        $normalized = trim($handle);
        if ($normalized === '') {
            return null;
        }

        return $this->handlers[$normalized] ?? null;
    }

    /**
     * Remove a handler for the given handle.
     *
     * @param  string  $handle  Form handle identifier
     */
    public function forget(string $handle): void
    {
        $normalized = trim($handle);
        if ($normalized === '') {
            return;
        }

        unset($this->handlers[$normalized]);
    }

    /**
     * Clear all registered handlers.
     */
    public function clear(): void
    {
        $this->handlers = [];
    }

    /**
     * Return all registered handlers.
     *
     * @return array<string, class-string|callable|CustomFormHandler>
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
