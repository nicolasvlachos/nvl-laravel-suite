<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Nvl\Forms\Contracts\FormRenderDataProvider;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;

/**
 * Registry for form render data providers.
 *
 * Allows modules to register providers that inject additional data into
 * form render pages based on form handle.
 */
final class FormRenderDataRegistry
{
    /**
     * Registered providers by form handle.
     *
     * @var array<string, class-string<FormRenderDataProvider>|FormRenderDataProvider>
     */
    private array $providers = [];

    /**
     * Create the registry with container for provider resolution.
     *
     * @param  Container  $container  IoC container
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Register a provider for a specific form handle.
     *
     * @param  string  $handle  Form handle
     * @param  class-string<FormRenderDataProvider>|FormRenderDataProvider  $provider  Provider class or instance
     */
    public function register(string $handle, string|FormRenderDataProvider $provider): void
    {
        $handle = trim($handle);
        if ($handle === '') {
            throw new FormException('Render-data provider handle must be non-empty.');
        }

        if (array_key_exists($handle, $this->providers)) {
            throw new FormException("A render-data provider is already registered for [{$handle}].");
        }

        $this->providers[$handle] = $provider;
    }

    /**
     * Check if a provider is registered for the given handle.
     *
     * @param  string  $handle  Form handle
     * @return bool True if provider exists
     */
    public function has(string $handle): bool
    {
        return isset($this->providers[$handle]);
    }

    /**
     * Get additional render data for a form.
     *
     * @param  Form  $form  Form being rendered
     * @param  Request  $request  Current request
     * @return array<string, mixed> Additional data to merge into page props
     *
     * @throws BindingResolutionException
     */
    public function getData(Form $form, Request $request): array
    {
        $handle = $form->handle;

        if (! $this->has($handle)) {
            return [];
        }

        $provider = $this->resolveProvider($handle);

        return $provider?->getData($form, $request) ?? [];
    }

    /**
     * Get additional translations for a form.
     *
     * @param  Form  $form  Form being rendered
     * @return array<string, mixed> Translation data to merge
     *
     * @throws BindingResolutionException
     */
    public function getTranslations(Form $form): array
    {
        $handle = $form->handle;

        if (! $this->has($handle)) {
            return [];
        }

        $provider = $this->resolveProvider($handle);

        return $provider?->getTranslations($form) ?? [];
    }

    /**
     * Resolve a provider instance from handle.
     *
     * @param  string  $handle  Form handle
     * @return FormRenderDataProvider|null Resolved provider or null
     *
     * @throws BindingResolutionException
     */
    private function resolveProvider(string $handle): ?FormRenderDataProvider
    {
        $entry = $this->providers[$handle] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry instanceof FormRenderDataProvider) {
            return $entry;
        }

        $instance = $this->container->make($entry);

        return $instance instanceof FormRenderDataProvider ? $instance : null;
    }
}
