<?php

declare(strict_types=1);

namespace Nvl\Content\Providers;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Content\Console\ContentDoctorCommand;
use Nvl\Content\Console\MigrateContentDefinitionsCommand;
use Nvl\Content\Console\PublishContentViewsCommand;
use Nvl\Content\Console\SyncContentDefinitionsCommand;
use Nvl\Content\Content;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentDefinitionMigration;
use Nvl\Content\Contracts\ContentFieldPreset;
use Nvl\Content\Contracts\ContentFieldTypeAdapter;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Contracts\ContentOwnerRegistrar;
use Nvl\Content\Contracts\ContentReferenceResolver;
use Nvl\Content\FieldPresets\BannerContentFieldPreset;
use Nvl\Content\FieldPresets\ButtonContentFieldPreset;
use Nvl\Content\FieldPresets\ConfiguredContentFieldPreset;
use Nvl\Content\FieldPresets\HeadingContentFieldPreset;
use Nvl\Content\FieldPresets\ImageContentFieldPreset;
use Nvl\Content\FieldPresets\LinkContentFieldPreset;
use Nvl\Content\FieldTypes\BooleanFieldTypeAdapter;
use Nvl\Content\FieldTypes\JsonFieldTypeAdapter;
use Nvl\Content\FieldTypes\MediaFieldTypeAdapter;
use Nvl\Content\FieldTypes\MultiSelectFieldTypeAdapter;
use Nvl\Content\FieldTypes\NumberFieldTypeAdapter;
use Nvl\Content\FieldTypes\ReferenceFieldTypeAdapter;
use Nvl\Content\FieldTypes\RichTextFieldTypeAdapter;
use Nvl\Content\FieldTypes\StringFieldTypeAdapter;
use Nvl\Content\FieldTypes\StructuredFieldTypeAdapter;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Services\ConfiguredContentAuthorization;
use Nvl\Content\Services\ContentDefinitionLoader;
use Nvl\Content\Services\ContentDefinitionMigrationRegistry;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Services\ContentJsonSchemaBuilder;
use Nvl\Content\Services\ContentLocalizedValues;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentReferenceRegistry;
use Nvl\Content\Services\ContentSchemaCompiler;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Support\ContentUriSchemePolicy;
use Nvl\Content\Validation\ContentSchemaValidator;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Opis\JsonSchema\Validator;

/**
 * Registers the standalone, headless Content package.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfiguration(__DIR__.'/../../config/content.php', 'content');
        $this->validateUriSchemeConfiguration();
        $authorization = config(
            'content.authorization.class',
            ConfiguredContentAuthorization::class,
        );

        if (! is_string($authorization)
            || ! is_a($authorization, ContentAuthorization::class, true)) {
            throw new InvalidArgumentException(
                'content.authorization.class must implement ContentAuthorization.',
            );
        }

        $this->app->bindIf(ContentAuthorization::class, $authorization);
        $this->app->singleton(ContentDefinitionRegistry::class);
        $this->app->singleton(ContentDefinitionMigrationRegistry::class);
        $this->app->singleton(ContentFieldPresetRegistry::class);
        $this->app->singleton(ContentFieldTypeRegistry::class);
        $this->app->singleton(ContentJsonSchemaBuilder::class);
        $this->app->scoped(ContentLocalizedValues::class);
        $this->app->singleton(ContentOwnerRegistry::class);
        $this->app->alias(ContentOwnerRegistry::class, ContentOwnerRegistrar::class);
        $this->app->singleton(ContentReferenceRegistry::class);
        $this->app->singleton(ContentSchemaCompiler::class);
        $this->app->scoped(Content::class);
        $this->app->singleton(Validator::class);
    }

    private function mergeConfiguration(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration
            && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require $path;
        $configured = $this->app->make('config')->get($key, []);

        if (! is_array($defaults) || ! is_array($configured)) {
            throw new InvalidArgumentException(
                "The [{$key}] configuration root must be an array.",
            );
        }

        $this->app->make('config')->set(
            $key,
            $this->mergeConfigurationValues($defaults, $configured),
        );
    }

    /**
     * Recursively fill associative maps while replacing configured lists wholesale.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $configured
     * @return array<array-key, mixed>
     */
    private function mergeConfigurationValues(array $defaults, array $configured): array
    {
        foreach ($configured as $key => $value) {
            $default = $defaults[$key] ?? null;
            $defaults[$key] = is_array($default)
                && is_array($value)
                && ! array_is_list($default)
                && ! array_is_list($value)
                    ? $this->mergeConfigurationValues($default, $value)
                    : $value;
        }

        return $defaults;
    }

    private function validateUriSchemeConfiguration(): void
    {
        foreach ([
            'content.links.allowed_schemes',
            'content.validation.url_schemes',
            'content.rich_text.allowed_link_schemes',
        ] as $key) {
            ContentUriSchemePolicy::validateAllowedSchemes(
                ContentConfiguration::stringList($key),
                $key,
            );
        }
    }

    public function boot(
        TypeScriptSourceRegistry $typeScriptSources,
        TranslationResourceRegistry $translationResources,
        ContentDefinitionLoader $loader,
        ContentDefinitionRegistry $definitions,
        ContentDefinitionMigrationRegistry $definitionMigrations,
        ContentFieldPresetRegistry $presets,
        ContentFieldTypeRegistry $fieldTypes,
        ContentSchemaCompiler $schemaCompiler,
        ContentSchemaValidator $schemas,
        ContentOwnerRegistry $owners,
        ContentReferenceRegistry $references,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/content');
        $this->registerFieldTypes($fieldTypes);
        $this->registerFieldPresets($presets);
        $this->validateFieldPresets($presets, $schemaCompiler, $schemas);
        $this->registerReferences($references);
        $this->registerDefinitionMigrations($definitionMigrations);
        $this->registerDefinitions($loader, $definitions);
        $this->registerOwners($owners);

        if ((bool) config('content.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'nvl-content');

        $translationResources->register(
            key: 'content.blocks',
            modelClass: ContentBlock::class,
            label: 'Content blocks',
            searchableColumns: ['key', 'scope', 'scope_key', 'status'],
            displayColumns: ['key', 'scope', 'scope_key', 'status', 'revision'],
            orderColumn: 'updated_at',
        );

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ContentDoctorCommand::class,
                MigrateContentDefinitionsCommand::class,
                PublishContentViewsCommand::class,
                SyncContentDefinitionsCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../../config/content.php' => config_path('content.php'),
        ], 'content-config');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'content-migrations');
        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/nvl-content'),
        ], 'content-views');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'content-skills');
    }

    private function registerFieldTypes(ContentFieldTypeRegistry $registry): void
    {
        foreach ([
            'text',
            'textarea',
            'date',
            'date_time',
            'url',
            'uri',
            'email',
            'color',
            'select',
        ] as $type) {
            $registry->register(new StringFieldTypeAdapter($type));
        }

        $registry->register(new BooleanFieldTypeAdapter);
        $registry->register(new NumberFieldTypeAdapter(integer: true));
        $registry->register(new NumberFieldTypeAdapter(integer: false));
        $registry->register(new MultiSelectFieldTypeAdapter);
        $registry->register(new RichTextFieldTypeAdapter);
        $registry->register($this->app->make(JsonFieldTypeAdapter::class));
        $registry->register(new StructuredFieldTypeAdapter('object', list: false));
        $registry->register(new StructuredFieldTypeAdapter('list', list: true));
        $registry->register(new StructuredFieldTypeAdapter('repeater', list: true));
        $registry->register(new StructuredFieldTypeAdapter('table', list: true));
        $registry->register($this->app->make(MediaFieldTypeAdapter::class, ['multiple' => false]));
        $registry->register($this->app->make(MediaFieldTypeAdapter::class, ['multiple' => true]));
        $registry->register($this->app->make(ReferenceFieldTypeAdapter::class, ['multiple' => false]));
        $registry->register($this->app->make(ReferenceFieldTypeAdapter::class, ['multiple' => true]));
        $configured = config('content.field_types', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('content.field_types must be an array.');
        }

        foreach ($configured as $alias => $class) {
            if (! is_string($alias)
                || ! is_string($class)
                || ! is_a($class, ContentFieldTypeAdapter::class, true)) {
                throw new InvalidArgumentException('Every configured content field type is invalid.');
            }

            $adapter = $this->app->make($class);

            if (! $adapter instanceof ContentFieldTypeAdapter || $adapter->alias() !== $alias) {
                throw new InvalidArgumentException(
                    "Configured content field adapter [{$class}] does not provide alias [{$alias}].",
                );
            }

            $registry->register($adapter);
        }
    }

    /**
     * Register built-in and consumer-configured semantic field presets.
     */
    private function registerFieldPresets(ContentFieldPresetRegistry $registry): void
    {
        foreach ([
            new LinkContentFieldPreset,
            new ButtonContentFieldPreset,
            new ImageContentFieldPreset,
            new HeadingContentFieldPreset,
            new BannerContentFieldPreset,
        ] as $preset) {
            $registry->register($preset);
        }

        $configured = config('content.presets', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('content.presets must be an array.');
        }

        foreach ($configured as $alias => $configuration) {
            if (! is_string($alias)) {
                throw new InvalidArgumentException(
                    'Every configured content field preset requires a string alias.',
                );
            }

            if (is_string($configuration)
                && is_a($configuration, ContentFieldPreset::class, true)) {
                $preset = $this->app->make($configuration);

                if (! $preset instanceof ContentFieldPreset || $preset->alias() !== $alias) {
                    throw new InvalidArgumentException(
                        "Configured content field preset [{$configuration}] does not provide alias [{$alias}].",
                    );
                }

                $registry->register($preset);

                continue;
            }

            if (! is_array($configuration)) {
                throw new InvalidArgumentException(
                    "Configured content field preset [{$alias}] is invalid.",
                );
            }

            $configuration = ContentArrays::stringMap(
                $configuration,
                "content field preset {$alias}",
            );
            $unknown = array_diff(
                array_keys($configuration),
                ['name', 'description', 'definition'],
            );
            $name = $configuration['name'] ?? null;
            $description = $configuration['description'] ?? null;
            $definition = $configuration['definition'] ?? null;

            if ($unknown !== []
                || ! is_string($name)
                || $description !== null && ! is_string($description)
                || ! is_array($definition)) {
                throw new InvalidArgumentException(
                    "Configured content field preset [{$alias}] has an invalid definition.",
                );
            }

            $registry->register(new ConfiguredContentFieldPreset(
                presetAlias: $alias,
                presetName: $name,
                presetDescription: $description,
                fieldDefinition: ContentArrays::stringMap(
                    $definition,
                    "content field preset {$alias} definition",
                ),
            ));
        }
    }

    /**
     * Compile and validate every preset during boot, including unused custom presets.
     */
    private function validateFieldPresets(
        ContentFieldPresetRegistry $registry,
        ContentSchemaCompiler $compiler,
        ContentSchemaValidator $schemas,
    ): void {
        foreach ($registry->all() as $preset) {
            $schemas->validate(new ContentSchema([
                $compiler->compilePreset($preset),
            ]));
        }
    }

    private function registerDefinitions(
        ContentDefinitionLoader $loader,
        ContentDefinitionRegistry $registry,
    ): void {
        foreach ($loader->load() as $definition) {
            $registry->register($definition);
        }
    }

    private function registerDefinitionMigrations(
        ContentDefinitionMigrationRegistry $registry,
    ): void {
        $configured = config('content.definition_migrations', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                'content.definition_migrations must be an array.',
            );
        }

        foreach ($configured as $class) {
            if (! is_string($class)
                || ! is_a($class, ContentDefinitionMigration::class, true)) {
                throw new InvalidArgumentException(
                    'Every configured content definition migration is invalid.',
                );
            }

            $migration = $this->app->make($class);

            if (! $migration instanceof ContentDefinitionMigration) {
                throw new InvalidArgumentException(
                    "Configured content definition migration [{$class}] is invalid.",
                );
            }

            $registry->register($migration);
        }
    }

    private function registerOwners(ContentOwnerRegistry $registry): void
    {
        $configured = config('content.owners', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('content.owners must be an array.');
        }

        foreach ($configured as $alias => $model) {
            if (! is_string($alias)
                || ! is_string($model)
                || ! is_a($model, Model::class, true)
                || ! is_a($model, ContentOwner::class, true)) {
                throw new InvalidArgumentException('Every configured content owner is invalid.');
            }

            /** @var class-string<Model&ContentOwner> $model */
            $registry->register($alias, $model);
        }
    }

    private function registerReferences(ContentReferenceRegistry $registry): void
    {
        $configured = config('content.references', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('content.references must be an array.');
        }

        foreach ($configured as $alias => $resolver) {
            if (! is_string($alias)
                || ! is_string($resolver)
                || ! is_a($resolver, ContentReferenceResolver::class, true)) {
                throw new InvalidArgumentException('Every configured content reference is invalid.');
            }

            $registry->register($alias, $resolver);
        }
    }
}
