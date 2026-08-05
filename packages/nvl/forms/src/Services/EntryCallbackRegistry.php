<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Nvl\Forms\Contracts\EntrySubmissionCallback;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Throwable;

/**
 * Registry for dispatching configured form entry callbacks.
 */
final class EntryCallbackRegistry
{
    /**
     * @var array<string, list<EntrySubmissionCallback|callable|string>>
     */
    private array $callbacks = [];

    /**
     * Create the registry with a container dependency.
     *
     * @param  Container  $container  IoC container instance
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register callback handlers for a form handle.
     *
     * @param  string  $handle  Form handle identifier
     * @param  EntrySubmissionCallback|callable|string|array<int, EntrySubmissionCallback|callable|string>  $callbacks  Callback entries
     */
    public function register(string $handle, EntrySubmissionCallback|callable|string|array $callbacks): void
    {
        $normalized = trim($handle);
        if ($normalized === '') {
            throw new FormException('Entry callback handle must be a non-empty string.');
        }

        $entries = $this->normalizeCallbacks($callbacks);
        if ($entries === []) {
            return;
        }

        $existing = $this->callbacks[$normalized] ?? [];
        foreach ($entries as $entry) {
            if (in_array($entry, $existing, true)) {
                throw new FormException("The entry callback is already registered for [{$normalized}].");
            }
        }

        $this->callbacks[$normalized] = array_merge($existing, $entries);
    }

    /**
     * Dispatch all configured callbacks for the given form handle.
     *
     * This method runs outside the entry-creation transaction — the entry is
     * already committed when callbacks fire. Each callback is isolated so a
     * failing integration cannot turn a durable submission into a client-visible
     * failure or prevent later callbacks from running.
     *
     * @param  Form  $form  Form model instance
     * @param  FormEntry  $entry  Persisted entry model instance
     * @param  Request  $request  HTTP request instance
     */
    public function dispatch(Form $form, FormEntry $entry, Request $request): void
    {
        $callbacks = $this->callbacks[$form->handle] ?? [];

        foreach ($callbacks as $cb) {
            try {
                $this->invoke($cb, $form, $entry, $request);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
    }

    /**
     * Remove callbacks for a form handle.
     *
     * @param  string  $handle  Form handle identifier
     */
    public function forget(string $handle): void
    {
        $normalized = trim($handle);
        if ($normalized === '') {
            return;
        }

        unset($this->callbacks[$normalized]);
    }

    /**
     * Clear all registered callbacks.
     */
    public function clear(): void
    {
        $this->callbacks = [];
    }

    /**
     * Invoke a configured callback entry.
     *
     * @param  EntrySubmissionCallback|callable|string  $cb  Callback entry
     * @param  Form  $form  Form model instance
     * @param  FormEntry  $entry  Entry model instance
     * @param  Request  $request  HTTP request instance
     */
    private function invoke(EntrySubmissionCallback|callable|string $cb, Form $form, FormEntry $entry, Request $request): void
    {
        if ($cb instanceof EntrySubmissionCallback) {
            $cb->after($form, $entry, $request);

            return;
        }

        if (is_string($cb) && $cb !== '') {
            $obj = $this->container->make($cb);
            if (! is_object($obj)) {
                return;
            }

            if ($obj instanceof EntrySubmissionCallback) {
                $obj->after($form, $entry, $request);

                return;
            }
            // Try common method names
            foreach (['handle', 'execute', '__invoke'] as $method) {
                if (is_callable([$obj, $method])) {
                    $obj->{$method}($form, $entry, $request);

                    return;
                }
            }

            return;
        }

        if (is_callable($cb)) {
            $cb($form, $entry, $request);
        }
    }

    /**
     * Normalize callback registrations while preserving array callables as one callback.
     *
     * @param  EntrySubmissionCallback|callable|string|array<int, EntrySubmissionCallback|callable|string>  $callbacks  Callback entries
     * @return list<EntrySubmissionCallback|callable|string>
     */
    private function normalizeCallbacks(EntrySubmissionCallback|callable|string|array $callbacks): array
    {
        if (is_array($callbacks) && ! is_callable($callbacks)) {
            $entries = [];

            foreach ($callbacks as $callback) {
                if ($callback instanceof EntrySubmissionCallback || is_string($callback) || is_callable($callback)) {
                    $entries[] = $callback;
                }
            }

            return $entries;
        }

        return [$callbacks];
    }
}
