<?php

declare(strict_types=1);

namespace Nvl\Templates\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwnerRegistrar;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Templates\Console\AdoptTemplatesCommand;
use Nvl\Templates\Console\PublishTemplateViewsCommand;
use Nvl\Templates\Console\RecoverTemplateRendersCommand;
use Nvl\Templates\Console\SyncTemplateDefinitionsCommand;
use Nvl\Templates\Console\TemplatesDoctorCommand;
use Nvl\Templates\Contracts\TemplateAssetResolver;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Contracts\TemplateOwnerResolver;
use Nvl\Templates\Contracts\TemplatePayloadValidator;
use Nvl\Templates\Contracts\TemplateRenderer;
use Nvl\Templates\Data\MediaTemplateAssetData;
use Nvl\Templates\Data\TemplateDefinitionData;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Pdf\Contracts\PdfServiceInterface;
use Nvl\Templates\Pdf\PdfService;
use Nvl\Templates\Services\ConfiguredTemplateAuthorization;
use Nvl\Templates\Services\ConfiguredTemplatePayloadValidator;
use Nvl\Templates\Services\MediaTemplateAssetRegistry;
use Nvl\Templates\Services\MediaTemplateAssetResolver;
use Nvl\Templates\Services\NullTemplateAssetResolver;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Services\TemplateOwnerRegistry;
use Nvl\Templates\Services\TemplateRendererRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/**
 * Registers the composable rendering core and database-backed implementation.
 */
final class TemplatesServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration, contracts, and renderer registry.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/templates.php', 'templates');
        $authorization = config(
            'templates.authorization.class',
            ConfiguredTemplateAuthorization::class,
        );

        if (! is_string($authorization)
            || ! is_a($authorization, TemplateAuthorization::class, true)) {
            throw new InvalidArgumentException(
                'templates.authorization.class must implement TemplateAuthorization.',
            );
        }

        $this->app->bindIf(TemplateAuthorization::class, $authorization);
        $this->app->bindIf(
            TemplatePayloadValidator::class,
            ConfiguredTemplatePayloadValidator::class,
        );
        $this->app->bindIf(PdfServiceInterface::class, PdfService::class);
        $assetDriver = config('templates.assets.driver', 'null');

        if (! is_string($assetDriver) || ! in_array($assetDriver, ['null', 'media'], true)) {
            throw new InvalidArgumentException('templates.assets.driver must be null or media.');
        }

        $this->app->singleton(MediaTemplateAssetRegistry::class);
        $this->app->bindIf(
            TemplateAssetResolver::class,
            $assetDriver === 'media'
                ? MediaTemplateAssetResolver::class
                : NullTemplateAssetResolver::class,
        );
        $this->app->singleton(TemplateDefinitionRegistry::class);
        $this->app->singleton(TemplateRendererRegistry::class);
        $this->app->singleton(TemplateOwnerRegistry::class);
    }

    /**
     * Register renderers, views, commands, and publishable package resources.
     */
    public function boot(
        TypeScriptSourceRegistry $typeScriptSources,
        TranslationResourceRegistry $translationResources,
        TemplateDefinitionRegistry $definitions,
        TemplateRendererRegistry $renderers,
        TemplateOwnerRegistry $owners,
        ContentOwnerRegistrar $contentOwners,
    ): void {
        $assets = $this->app->make(MediaTemplateAssetRegistry::class);
        $typeScriptSources->register(__DIR__.'/..', 'nvl/templates');
        $this->registerRenderers($renderers);
        $this->loadViewsFrom(
            __DIR__.'/../../resources/views',
            $this->viewNamespace(),
        );
        $this->registerDefinitions($definitions);
        $this->registerOwners($owners);
        $this->registerMediaAssets($assets);
        $registeredContentOwner = $contentOwners->registered(
            TemplateVersion::CONTENT_OWNER_TYPE,
        );

        if ($registeredContentOwner === null) {
            $contentOwners->register(
                TemplateVersion::CONTENT_OWNER_TYPE,
                TemplateVersion::class,
            );
        } elseif ($registeredContentOwner !== TemplateVersion::class) {
            throw new InvalidArgumentException(
                'Content owner alias [template-version] must resolve to TemplateVersion.',
            );
        }
        if ((bool) config('templates.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $translationResources->register(
            key: 'templates.templates',
            modelClass: Template::class,
            label: 'Templates',
            searchableColumns: ['key', 'renderer', 'status'],
            displayColumns: ['key', 'renderer', 'status', 'revision'],
            orderColumn: 'updated_at',
        );
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdoptTemplatesCommand::class,
                SyncTemplateDefinitionsCommand::class,
                TemplatesDoctorCommand::class,
                PublishTemplateViewsCommand::class,
                RecoverTemplateRendersCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../../config/templates.php' => config_path('templates.php'),
        ], 'templates-config');
        $this->publishes([
            __DIR__.'/../../resources/views' => $this->publishedViewPath(),
        ], 'templates-views');
        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'templates-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'templates-skills');
    }

    private function registerRenderers(TemplateRendererRegistry $registry): void
    {
        $configured = config('templates.renderers', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('templates.renderers must be an array.');
        }

        foreach ($configured as $alias => $renderer) {
            if (! is_string($alias)
                || ! is_string($renderer)
                || ! is_a($renderer, TemplateRenderer::class, true)) {
                throw new InvalidArgumentException('Every configured template renderer is invalid.');
            }

            $registry->register($alias, $renderer);
        }
    }

    private function registerDefinitions(TemplateDefinitionRegistry $registry): void
    {
        $configured = config('templates.definitions', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('templates.definitions must be an array.');
        }

        foreach ($configured as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new InvalidArgumentException(
                    'Every configured template definition is invalid.',
                );
            }

            foreach ([
                'renderer_options' => 'rendererOptions',
                'subject_path' => 'subjectPath',
                'required_regions' => 'requiredRegions',
                'allowed_content_definitions' => 'allowedContentDefinitions',
            ] as $configuredKey => $property) {
                if (array_key_exists($configuredKey, $definition)
                    && array_key_exists($property, $definition)) {
                    throw new InvalidArgumentException(
                        "Template definition [{$key}] cannot declare both [{$configuredKey}] and [{$property}].",
                    );
                }

                if (array_key_exists($configuredKey, $definition)) {
                    $definition[$property] = $definition[$configuredKey];
                    unset($definition[$configuredKey]);
                }
            }

            $allowed = [
                'renderer',
                'view',
                'profiles',
                'schema',
                'rendererOptions',
                'subjectPath',
                'requiredRegions',
                'allowedContentDefinitions',
            ];
            $unknown = array_diff(array_keys($definition), $allowed);

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    "Template definition [{$key}] contains unknown option [".
                    (string) reset($unknown).'].',
                );
            }

            $registry->register(
                TemplateDefinitionData::from(['key' => $key, ...$definition]),
            );
        }
    }

    private function registerOwners(TemplateOwnerRegistry $registry): void
    {
        $configured = config('templates.owners', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('templates.owners must be an array.');
        }

        foreach ($configured as $alias => $resolver) {
            if (! is_string($alias)
                || ! is_string($resolver)
                || ! is_a($resolver, TemplateOwnerResolver::class, true)) {
                throw new InvalidArgumentException(
                    'Every configured template owner is invalid.',
                );
            }

            $registry->register($alias, $resolver);
        }
    }

    private function registerMediaAssets(MediaTemplateAssetRegistry $registry): void
    {
        $configured = config('templates.assets.media.aliases', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('templates.assets.media.aliases must be an array.');
        }

        foreach ($configured as $key => $asset) {
            if (! is_string($key) || ! is_array($asset)) {
                throw new InvalidArgumentException(
                    'Every configured template Media alias must be a keyed object.',
                );
            }

            $unknown = array_diff(array_keys($asset), [
                'media_id',
                'scope',
                'type',
                'variation',
                'delivery',
                'expected_revision',
            ]);

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    "Template Media alias [{$key}] contains unknown option [".
                    (string) reset($unknown).'].',
                );
            }

            $mediaId = $asset['media_id'] ?? null;
            $scope = $asset['scope'] ?? 'default';
            $type = $asset['type'] ?? 'image';
            $variation = $asset['variation'] ?? '';
            $delivery = $asset['delivery'] ?? 'path';
            $expectedRevision = $asset['expected_revision'] ?? null;

            if (! is_string($mediaId)
                || ! is_string($scope)
                || ! is_string($type)
                || ! is_string($variation)
                || ! is_string($delivery)
                || ($expectedRevision !== null && ! is_int($expectedRevision))) {
                throw new InvalidArgumentException(
                    "Template Media alias [{$key}] contains invalid values.",
                );
            }

            $registry->register(new MediaTemplateAssetData(
                key: $key,
                mediaId: $mediaId,
                scope: $scope,
                type: $type,
                variation: $variation,
                delivery: $delivery,
                expectedRevision: $expectedRevision,
            ));
        }
    }

    private function viewNamespace(): string
    {
        $namespace = config('templates.views.namespace', 'nvl-templates');

        if (! is_string($namespace)
            || preg_match('/^[a-z][a-z0-9-]*$/', $namespace) !== 1) {
            throw new InvalidArgumentException(
                'templates.views.namespace must be a lowercase view namespace.',
            );
        }

        return $namespace;
    }

    private function publishedViewPath(): string
    {
        $path = config(
            'templates.views.publish_path',
            resource_path('views/vendor/nvl-templates'),
        );

        if (! is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(
                'templates.views.publish_path must be a non-empty path.',
            );
        }

        return $path;
    }
}
