<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Nvl\Forms\Contracts\FormErrorMapper;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Nvl\Support\Exceptions\BusinessException;

/**
 * Registry for form error mappers.
 *
 * Allows modules to register mappers that convert business exceptions
 * to form field errors based on form handle.
 */
final class FormErrorMapperRegistry
{
    /**
     * Registered mappers by form handle.
     *
     * @var array<string, class-string<FormErrorMapper>|FormErrorMapper>
     */
    private array $mappers = [];

    /**
     * Create the registry with container for mapper resolution.
     *
     * @param  Container  $container  IoC container
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register a mapper for a specific form handle.
     *
     * @param  string  $handle  Form handle
     * @param  class-string<FormErrorMapper>|FormErrorMapper  $mapper  Mapper class or instance
     */
    public function register(string $handle, string|FormErrorMapper $mapper): void
    {
        $handle = trim($handle);
        if ($handle === '') {
            throw new FormException('Error-mapper handle must be non-empty.');
        }

        if (array_key_exists($handle, $this->mappers)) {
            throw new FormException("An error mapper is already registered for [{$handle}].");
        }

        $this->mappers[$handle] = $mapper;
    }

    /**
     * Check if a mapper is registered for the given handle.
     *
     * @param  string  $handle  Form handle
     * @return bool True if mapper exists
     */
    public function has(string $handle): bool
    {
        return isset($this->mappers[$handle]);
    }

    /**
     * Map a business exception to form field errors.
     *
     * @param  Form  $form  Form that received the submission
     * @param  BusinessException  $exception  Exception to map
     * @return array<string, mixed>|null Mapped errors or null if no mapper handled it
     *
     * @throws BindingResolutionException
     */
    public function map(Form $form, BusinessException $exception): ?array
    {
        $handle = $form->handle;

        if (! $this->has($handle)) {
            return null;
        }

        $mapper = $this->resolveMapper($handle);

        return $mapper?->map($form, $exception);
    }

    /**
     * Resolve a mapper instance from handle.
     *
     * @param  string  $handle  Form handle
     * @return FormErrorMapper|null Resolved mapper or null
     *
     * @throws BindingResolutionException
     */
    private function resolveMapper(string $handle): ?FormErrorMapper
    {
        $entry = $this->mappers[$handle] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry instanceof FormErrorMapper) {
            return $entry;
        }

        $instance = $this->container->make($entry);

        return $instance instanceof FormErrorMapper ? $instance : null;
    }
}
