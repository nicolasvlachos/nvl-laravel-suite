<?php

declare(strict_types=1);

namespace Nvl\Suite\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Definitions\Tables\AuthTables;
use Nvl\Auth\Providers\AuthServiceProvider;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Definitions\Tables\ContentTables;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Csv\Providers\CsvServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Forms\Contracts\FormEntryDeletionPolicy;
use Nvl\Forms\Contracts\FormEntryPrivacyPolicy;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Contracts\FormSpamDetector;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Providers\FormsServiceProvider;
use Nvl\Forms\Support\FormHandlerRegistry;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageRequestContextResolver;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Providers\PagesServiceProvider;
use Nvl\Pages\Services\PageResourceRegistry;
use Nvl\Primitives\Providers\PrimitivesServiceProvider;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Contracts\SeoImageResolver;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Suite\Services\SuiteModuleSelection;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Providers\TemplatesServiceProvider;
use Nvl\Templates\Services\TemplateOwnerRegistry;
use Nvl\Templates\Services\TemplateRendererRegistry;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use RuntimeException;

/**
 * Defines the suite's dependency graph and consumer adoption surface.
 *
 * @phpstan-type MigrationDefinition (array{mode: 'configurable', config: string}|array{mode: 'domain-owned'|'none', config: null})
 * @phpstan-type AliasReader array{service: class-string, method: string}
 * @phpstan-type ScheduleDefinition array{command: string, enabled: string|null, required_when_enabled: bool}
 * @phpstan-type ConfigurationDefinition array{
 *     key: string,
 *     default: string,
 *     published: string,
 *     open_maps: list<string>,
 *     deprecated: array<string, string>,
 *     merge_strategy: 'deep-map-atomic-list'
 * }
 * @phpstan-type ModuleCoreDefinition array{
 *     provider: class-string<ServiceProvider>,
 *     dependencies: list<string>,
 *     stateful: bool,
 *     migration: MigrationDefinition,
 *     doctor: string|null,
 *     contracts: list<class-string>,
 *     aliases: list<AliasReader>,
 *     queues: list<string>,
 *     schedules: list<ScheduleDefinition>,
 *     typescript: bool
 * }
 * @phpstan-type ModuleDefinition array{
 *     provider: class-string<ServiceProvider>,
 *     dependencies: list<string>,
 *     stateful: bool,
 *     migration: MigrationDefinition,
 *     doctor: string|null,
 *     contracts: list<class-string>,
 *     aliases: list<AliasReader>,
 *     queues: list<string>,
 *     schedules: list<ScheduleDefinition>,
 *     typescript: bool,
 *     configuration: ConfigurationDefinition|null
 * }
 * @phpstan-type ProfileDefinition array{description: string, modules: list<string>}
 */
final readonly class SuiteModuleCatalog
{
    /**
     * @var array<string, ProfileDefinition>
     */
    private const array PROFILES = [
        'auth-only' => [
            'description' => 'Headless authentication, invitations, sessions, credentials, and RBAC.',
            'modules' => ['auth'],
        ],
        'content-platform' => [
            'description' => 'Pages, structured content, media, taxonomy, SEO, metafields, templates, and localization.',
            'modules' => ['pages', 'taxonomy', 'templates', 'translations'],
        ],
        'communications' => [
            'description' => 'Authentication-aware mail tracking, scheduled mail, forms, and reusable templates.',
            'modules' => ['auth', 'forms', 'mail-notifications', 'templates', 'translations'],
        ],
        'full-suite' => [
            'description' => 'Every NVL module and all transitive dependencies.',
            'modules' => [
                'support',
                'data',
                'filterable',
                'translatable',
                'activity',
                'auth',
                'csv',
                'mail-notifications',
                'media',
                'comments',
                'content',
                'metafields',
                'primitives',
                'seo',
                'settings',
                'taxonomy',
                'templates',
                'translations',
                'forms',
                'pages',
            ],
        ],
    ];

    /**
     * Canonical provider order and operational adoption metadata.
     *
     * @var array<string, ModuleCoreDefinition>
     */
    private const array MODULES = [
        'support' => [
            'provider' => SupportServiceProvider::class,
            'dependencies' => [],
            'stateful' => false,
            'migration' => ['mode' => 'none', 'config' => null],
            'doctor' => null,
            'contracts' => [],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => false,
        ],
        'data' => [
            'provider' => DataServiceProvider::class,
            'dependencies' => ['support'],
            'stateful' => false,
            'migration' => ['mode' => 'none', 'config' => null],
            'doctor' => null,
            'contracts' => [],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'filterable' => [
            'provider' => FilterableServiceProvider::class,
            'dependencies' => ['data'],
            'stateful' => false,
            'migration' => ['mode' => 'none', 'config' => null],
            'doctor' => null,
            'contracts' => [],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'translatable' => [
            'provider' => TranslatableServiceProvider::class,
            'dependencies' => ['data', 'support'],
            'stateful' => false,
            'migration' => ['mode' => 'domain-owned', 'config' => null],
            'doctor' => 'nvl:translatable:doctor',
            'contracts' => [],
            'aliases' => [
                ['service' => TranslationResourceRegistry::class, 'method' => 'keys'],
            ],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'activity' => [
            'provider' => ActivityServiceProvider::class,
            'dependencies' => ['data', 'support'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'activity.migrations.enabled'],
            'doctor' => 'nvl:activity:doctor',
            'contracts' => [],
            'aliases' => [],
            'queues' => ['maintenance'],
            'schedules' => [
                ['command' => 'nvl:activity:purge-system', 'enabled' => 'activity.retention.schedule.enabled', 'required_when_enabled' => false],
            ],
            'typescript' => true,
        ],
        'auth' => [
            'provider' => AuthServiceProvider::class,
            'dependencies' => ['data', 'support'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'nvl-auth.migrations.enabled'],
            'doctor' => 'nvl:auth:doctor',
            'contracts' => [AuthManagementAccess::class],
            'aliases' => [],
            'queues' => [],
            'schedules' => [
                ['command' => 'nvl:auth:prune', 'enabled' => null, 'required_when_enabled' => false],
            ],
            'typescript' => true,
        ],
        'csv' => [
            'provider' => CsvServiceProvider::class,
            'dependencies' => ['data'],
            'stateful' => false,
            'migration' => ['mode' => 'none', 'config' => null],
            'doctor' => null,
            'contracts' => [],
            'aliases' => [],
            'queues' => ['host-selected import/export queues'],
            'schedules' => [],
            'typescript' => true,
        ],
        'mail-notifications' => [
            'provider' => MailNotificationsServiceProvider::class,
            'dependencies' => ['support'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'mail-notifications.migrations.enabled'],
            'doctor' => 'nvl:mail-notifications:doctor',
            'contracts' => [MailNotificationReadAuthorization::class, ScheduledMailReadAuthorization::class],
            'aliases' => [
                ['service' => ProviderRegistry::class, 'method' => 'all'],
                ['service' => MailNotificationNotifiableTypeRegistry::class, 'method' => 'all'],
                ['service' => ScheduledMessageFactoryRegistry::class, 'method' => 'all'],
            ],
            'queues' => ['mail delivery queue'],
            'schedules' => [
                ['command' => 'nvl:mail-notifications:process-scheduled', 'enabled' => 'mail-notifications.scheduling.enabled', 'required_when_enabled' => true],
                ['command' => 'nvl:mail-notifications:recover-scheduled', 'enabled' => 'mail-notifications.scheduling.enabled', 'required_when_enabled' => true],
            ],
            'typescript' => true,
        ],
        'media' => [
            'provider' => MediaServiceProvider::class,
            'dependencies' => ['data', 'filterable', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'media.migrations.enabled'],
            'doctor' => 'nvl:media:doctor',
            'contracts' => [MediaAuthorization::class, MediaContentScanner::class, MultipartUploadGateway::class],
            'aliases' => [],
            'queues' => ['media conversions'],
            'schedules' => [
                ['command' => 'nvl:media:multipart:prune', 'enabled' => 'media.multipart.enabled', 'required_when_enabled' => true],
            ],
            'typescript' => true,
        ],
        'comments' => [
            'provider' => CommentsServiceProvider::class,
            'dependencies' => ['data', 'filterable', 'media', 'support'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'comments.migrations.enabled'],
            'doctor' => 'nvl:comments:doctor',
            'contracts' => [CommentAuthorization::class],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'content' => [
            'provider' => ContentServiceProvider::class,
            'dependencies' => ['data', 'filterable', 'media', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'content.migrations.enabled'],
            'doctor' => 'nvl:content:doctor',
            'contracts' => [ContentAuthorization::class],
            'aliases' => [
                ['service' => ContentOwnerRegistry::class, 'method' => 'aliases'],
            ],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'metafields' => [
            'provider' => MetafieldsServiceProvider::class,
            'dependencies' => ['data', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'metafields.migrations.enabled'],
            'doctor' => 'nvl:metafields:doctor',
            'contracts' => [MetafieldAuthorization::class, MetafieldReferenceAuthorization::class],
            'aliases' => [
                ['service' => MetafieldOwnerRegistry::class, 'method' => 'all'],
            ],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'primitives' => [
            'provider' => PrimitivesServiceProvider::class,
            'dependencies' => ['data', 'support'],
            'stateful' => false,
            'migration' => ['mode' => 'none', 'config' => null],
            'doctor' => null,
            'contracts' => [],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'seo' => [
            'provider' => SeoServiceProvider::class,
            'dependencies' => ['data', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'seo.migrations.enabled'],
            'doctor' => 'nvl:seo:doctor',
            'contracts' => [SeoAuthorization::class, SeoImageResolver::class, SitemapArtifactStore::class],
            'aliases' => [
                ['service' => SeoOwnerRegistry::class, 'method' => 'configured'],
            ],
            'queues' => [],
            'schedules' => [
                ['command' => 'nvl:seo:sitemap:warm', 'enabled' => null, 'required_when_enabled' => false],
                ['command' => 'nvl:seo:redirects:prune', 'enabled' => null, 'required_when_enabled' => false],
            ],
            'typescript' => true,
        ],
        'settings' => [
            'provider' => SettingsServiceProvider::class,
            'dependencies' => ['data', 'support'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'settings.migrations.enabled'],
            'doctor' => 'nvl:settings:doctor',
            'contracts' => [SettingsAuthorization::class, SettingsAuditContextProvider::class],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'taxonomy' => [
            'provider' => TaxonomyServiceProvider::class,
            'dependencies' => ['data', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'taxonomy.migrations.enabled'],
            'doctor' => 'nvl:taxonomy:doctor',
            'contracts' => [],
            'aliases' => [
                ['service' => TaxonomyOwnerRegistry::class, 'method' => 'all'],
            ],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'templates' => [
            'provider' => TemplatesServiceProvider::class,
            'dependencies' => ['content', 'data', 'filterable', 'media', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'templates.migrations.enabled'],
            'doctor' => 'nvl:templates:doctor',
            'contracts' => [TemplateAuthorization::class],
            'aliases' => [
                ['service' => TemplateOwnerRegistry::class, 'method' => 'aliases'],
                ['service' => TemplateRendererRegistry::class, 'method' => 'all'],
            ],
            'queues' => ['template rendering'],
            'schedules' => [
                ['command' => 'nvl:templates:renders:recover', 'enabled' => null, 'required_when_enabled' => false],
            ],
            'typescript' => true,
        ],
        'translations' => [
            'provider' => TranslationsServiceProvider::class,
            'dependencies' => ['data', 'filterable', 'support'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'translations.migrations.enabled'],
            'doctor' => 'nvl:translations:doctor',
            'contracts' => [TranslationsAuthorization::class],
            'aliases' => [],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
        'forms' => [
            'provider' => FormsServiceProvider::class,
            'dependencies' => ['data', 'filterable', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'forms.migrations.enabled'],
            'doctor' => 'nvl:forms:doctor',
            'contracts' => [FormRateLimiter::class, FormSpamDetector::class, FormEntryDeletionPolicy::class, FormEntryPrivacyPolicy::class],
            'aliases' => [
                ['service' => FormHandlerRegistry::class, 'method' => 'all'],
            ],
            'queues' => ['host-selected submission callbacks'],
            'schedules' => [],
            'typescript' => true,
        ],
        'pages' => [
            'provider' => PagesServiceProvider::class,
            'dependencies' => ['content', 'data', 'filterable', 'metafields', 'seo', 'support', 'translatable'],
            'stateful' => true,
            'migration' => ['mode' => 'configurable', 'config' => 'pages.migrations.enabled'],
            'doctor' => 'nvl:pages:doctor',
            'contracts' => [PageAuthorization::class, PageRequestContextResolver::class, PageUrlGenerator::class],
            'aliases' => [
                ['service' => PageResourceRegistry::class, 'method' => 'aliases'],
            ],
            'queues' => [],
            'schedules' => [],
            'typescript' => true,
        ],
    ];

    /**
     * Package configuration ownership and structural-extension metadata.
     *
     * Paths in open_maps and deprecated are relative to the package config key.
     * Open maps accept consumer-owned literal or computed child keys and therefore
     * end structural comparison at that branch.
     *
     * @var array<string, ConfigurationDefinition>
     */
    private const array CONFIGURATION = [
        'data' => [
            'key' => 'nvl-data',
            'default' => 'packages/nvl/data/config/nvl-data.php',
            'published' => 'nvl-data.php',
            'open_maps' => ['typescript.scope_mappings', 'typescript.type_replacements'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'translatable' => [
            'key' => 'translatable',
            'default' => 'packages/nvl/translatable/config/translatable.php',
            'published' => 'translatable.php',
            'open_maps' => ['labels', 'resources'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'activity' => [
            'key' => 'activity',
            'default' => 'packages/nvl/activity/config/activity.php',
            'published' => 'activity.php',
            'open_maps' => [],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'auth' => [
            'key' => 'nvl-auth',
            'default' => 'packages/nvl/auth/config/nvl-auth.php',
            'published' => 'nvl-auth.php',
            'open_maps' => [
                'features.social_identities.settings.providers',
                'management.abilities',
                'management.policy_models',
                'ownership.host_routes',
            ],
            'deprecated' => [
                'features.sessions.settings.maximum_concurrent_sessions' => 'Enforce session concurrency through Nvl\\Auth\\Contracts\\PrincipalSessionContainment.',
            ],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'mail-notifications' => [
            'key' => 'mail-notifications',
            'default' => 'packages/nvl/mail-notifications/config/mail-notifications.php',
            'published' => 'mail-notifications.php',
            'open_maps' => [
                'providers',
                'notifiable_types',
                'extensions.provider_adapters',
                'extensions.message_id_resolvers',
                'extensions.notifiable_type_providers',
                'extensions.scheduled_message_factories',
                'extensions.webhook_managers',
            ],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'media' => [
            'key' => 'media',
            'default' => 'packages/nvl/media/config/media.php',
            'published' => 'media.php',
            'open_maps' => [
                'file_types',
                'group_types',
                'image_formats',
                'image_variation_presets',
                'associable_mutation_abilities',
            ],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'comments' => [
            'key' => 'comments',
            'default' => 'packages/nvl/comments/config/comments.php',
            'published' => 'comments.php',
            'open_maps' => ['targets'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'content' => [
            'key' => 'content',
            'default' => 'packages/nvl/content/config/content.php',
            'published' => 'content.php',
            'open_maps' => [
                'definition_migrations',
                'definitions',
                'scopes',
                'owners',
                'references',
                'field_types',
                'presets',
            ],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'metafields' => [
            'key' => 'metafields',
            'default' => 'packages/nvl/metafields/config/metafields.php',
            'published' => 'metafields.php',
            'open_maps' => ['owners', 'reference_models'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'primitives' => [
            'key' => 'primitives',
            'default' => 'packages/nvl/primitives/config/primitives.php',
            'published' => 'primitives.php',
            'open_maps' => ['exchange_rates.rates', 'reference.cities', 'reference.banks'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'seo' => [
            'key' => 'seo',
            'default' => 'packages/nvl/seo/config/seo.php',
            'published' => 'seo.php',
            'open_maps' => ['owners', 'sitemap.sources', 'structured_data.providers'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'settings' => [
            'key' => 'settings',
            'default' => 'packages/nvl/settings/config/settings.php',
            'published' => 'settings.php',
            'open_maps' => [],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'taxonomy' => [
            'key' => 'taxonomy',
            'default' => 'packages/nvl/taxonomy/config/taxonomy.php',
            'published' => 'taxonomy.php',
            'open_maps' => ['owners', 'taxonomies'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'templates' => [
            'key' => 'templates',
            'default' => 'packages/nvl/templates/config/templates.php',
            'published' => 'templates.php',
            'open_maps' => [
                'definitions',
                'owners',
                'renderers',
                'assets.media.aliases',
            ],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'translations' => [
            'key' => 'translations',
            'default' => 'packages/nvl/translations/config/translations.php',
            'published' => 'translations.php',
            'open_maps' => ['custom_scopes', 'export_targets', 'scan.namespaces'],
            'deprecated' => [
                'authorization.class' => 'Bind Nvl\\Translations\\Contracts\\TranslationsAuthorization in the application container.',
            ],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'forms' => [
            'key' => 'forms',
            'default' => 'packages/nvl/forms/config/forms.php',
            'published' => 'forms.php',
            'open_maps' => [],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
        'pages' => [
            'key' => 'pages',
            'default' => 'packages/nvl/pages/config/pages.php',
            'published' => 'pages.php',
            'open_maps' => ['resources'],
            'deprecated' => [],
            'merge_strategy' => 'deep-map-atomic-list',
        ],
    ];

    public function __construct(private Repository $configuration) {}

    /**
     * Return every canonical module definition.
     *
     * @return array<string, ModuleDefinition>
     */
    public function modules(): array
    {
        $modules = [];

        foreach (self::MODULES as $module => $definition) {
            $modules[$module] = [
                ...$definition,
                'configuration' => self::CONFIGURATION[$module] ?? null,
            ];
        }

        return $modules;
    }

    /**
     * Return every documented installation profile.
     *
     * @return array<string, ProfileDefinition>
     */
    public function profiles(): array
    {
        return self::PROFILES;
    }

    /**
     * Return runtime-shipped package table definitions used by consumer audits.
     *
     * @return array<string, class-string>
     */
    public function tableDefinitions(): array
    {
        return [
            'activity' => ActivityTables::class,
            'auth' => AuthTables::class,
            'comments' => CommentsTables::class,
            'content' => ContentTables::class,
            'forms' => FormsTables::class,
            'mail-notifications' => MailNotificationsTables::class,
            'media' => MediaTables::class,
            'metafields' => MetafieldsTables::class,
            'pages' => PagesTables::class,
            'seo' => SeoTables::class,
            'settings' => SettingsTables::class,
            'taxonomy' => TaxonomyTables::class,
            'templates' => TemplatesTables::class,
            'translations' => TranslationsTables::class,
        ];
    }

    /**
     * Return package-owned management controller classes or namespace prefixes.
     *
     * A trailing namespace separator marks a prefix; every other entry is an
     * exact invokable or controller class.
     *
     * @return array<string, list<class-string|string>>
     */
    public function managementActions(): array
    {
        return [
            'activity' => ['Nvl\\Activity\\Http\\Controllers\\Api\\'],
            'auth' => ['Nvl\\Auth\\Http\\Controllers\\Management\\'],
            'comments' => ['Nvl\\Comments\\Http\\Controllers\\CommentsManagementController'],
            'content' => ['Nvl\\Content\\Http\\Controllers\\'],
            'forms' => ['Nvl\\Forms\\Http\\Controllers\\Api\\FormsApiController'],
            'media' => ['Nvl\\Media\\Http\\Controllers\\Api\\'],
            'metafields' => ['Nvl\\Metafields\\Http\\Controllers\\Api\\'],
            'pages' => ['Nvl\\Pages\\Http\\Controllers\\PagesManagementController'],
            'seo' => ['Nvl\\Seo\\Http\\Controllers\\SeoManagementController'],
            'settings' => ['Nvl\\Settings\\Http\\Controllers\\SettingsManagementController'],
            'templates' => ['Nvl\\Templates\\Http\\Controllers\\TemplatesController'],
            'translations' => ['Nvl\\Translations\\Http\\Controllers\\Api\\'],
        ];
    }

    /**
     * Resolve one installation profile through the canonical dependency graph.
     *
     * @return list<string>
     */
    public function profileModules(string $profile): array
    {
        if (! isset(self::PROFILES[$profile])) {
            throw new RuntimeException("Unknown suite installation profile [{$profile}].");
        }

        return $this->resolveModules(array_fill_keys(self::PROFILES[$profile]['modules'], true));
    }

    /**
     * Resolve the current effective module list, including transitive dependencies.
     *
     * @return list<string>
     */
    public function effectiveModules(): array
    {
        return $this->selection()->effectiveModules();
    }

    /**
     * Resolve enabled providers in dependency-safe canonical order.
     *
     * @return list<class-string<ServiceProvider>>
     */
    public function effectiveProviders(): array
    {
        return array_map(
            static fn (string $module): string => self::MODULES[$module]['provider'],
            $this->effectiveModules(),
        );
    }

    /**
     * Return whether a module is selected as a root before dependency closure.
     */
    public function requested(string $module): bool
    {
        return $this->selection()->requested($module);
    }

    /**
     * Return the consumer's explicit or omitted-disabled module decision.
     *
     * @return 'enabled'|'disabled'|'implicit'
     */
    public function moduleDecision(string $module): string
    {
        return $this->selection()->decision($module);
    }

    /**
     * Resolve the current runtime selection through the shared selection model.
     */
    public function selection(): SuiteModuleSelection
    {
        $configured = $this->configuration->get('nvl-suite', []);

        if (! is_array($configured)) {
            throw new RuntimeException('nvl-suite must contain an array.');
        }

        return SuiteModuleSelection::fromConfiguration($configured, $this);
    }

    /**
     * @param  array<string, true>  $requested
     * @return list<string>
     */
    private function resolveModules(array $requested): array
    {
        $selected = [];

        foreach (array_keys($requested) as $module) {
            if (! isset(self::MODULES[$module])) {
                throw new RuntimeException("Unknown suite module [{$module}].");
            }

            $this->selectModule($module, $selected);
        }

        return array_values(array_filter(
            array_keys(self::MODULES),
            static fn (string $module): bool => isset($selected[$module]),
        ));
    }

    /**
     * @param  array<string, true>  $selected
     */
    private function selectModule(string $module, array &$selected): void
    {
        foreach (self::MODULES[$module]['dependencies'] as $dependency) {
            $this->selectModule($dependency, $selected);
        }

        $selected[$module] = true;
    }
}
