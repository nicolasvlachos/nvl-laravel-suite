<?php

declare(strict_types=1);

namespace Nvl\Suite\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Providers\AuthServiceProvider;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Csv\Providers\CsvServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Forms\Contracts\FormEntryDeletionPolicy;
use Nvl\Forms\Contracts\FormEntryPrivacyPolicy;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Contracts\FormSpamDetector;
use Nvl\Forms\Providers\FormsServiceProvider;
use Nvl\Forms\Support\FormHandlerRegistry;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageRequestContextResolver;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Providers\PagesServiceProvider;
use Nvl\Pages\Services\PageResourceRegistry;
use Nvl\Primitives\Providers\PrimitivesServiceProvider;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Contracts\SeoImageResolver;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Providers\SeoServiceProvider;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Providers\TemplatesServiceProvider;
use Nvl\Templates\Services\TemplateOwnerRegistry;
use Nvl\Templates\Services\TemplateRendererRegistry;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use RuntimeException;

/**
 * Defines the suite's dependency graph and consumer adoption surface.
 *
 * @phpstan-type MigrationDefinition (array{mode: 'configurable', config: string}|array{mode: 'domain-owned'|'none', config: null})
 * @phpstan-type AliasReader array{service: class-string, method: string}
 * @phpstan-type ScheduleDefinition array{command: string, enabled: string|null, required_when_enabled: bool}
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
 *     typescript: bool
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
     * @var array<string, ModuleDefinition>
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
            'dependencies' => [],
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
            'dependencies' => ['data'],
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
            'dependencies' => ['data'],
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
            'dependencies' => [],
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
            'dependencies' => ['data', 'filterable', 'media'],
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
        'seo' => [
            'provider' => SeoServiceProvider::class,
            'dependencies' => ['data', 'translatable'],
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
            'dependencies' => ['data'],
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
            'dependencies' => ['data', 'translatable'],
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
            'dependencies' => ['content', 'data', 'filterable', 'media', 'translatable'],
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
            'dependencies' => ['content', 'data', 'filterable', 'metafields', 'seo', 'translatable'],
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

    public function __construct(private Repository $configuration) {}

    /**
     * Return every canonical module definition.
     *
     * @return array<string, ModuleDefinition>
     */
    public function modules(): array
    {
        return self::MODULES;
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
        $configured = $this->configuredModules();
        $requested = [];

        foreach (self::MODULES as $module => $_definition) {
            $enabled = array_key_exists($module, $configured) ? $configured[$module] : true;

            if ($enabled) {
                $requested[$module] = true;
            }
        }

        return $this->resolveModules($requested);
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
     * Return whether a module was explicitly or implicitly requested.
     */
    public function requested(string $module): bool
    {
        $configured = $this->configuredModules();
        $value = array_key_exists($module, $configured) ? $configured[$module] : true;

        return $value;
    }

    /**
     * @return array<string, bool>
     */
    private function configuredModules(): array
    {
        $configured = $this->configuration->get('nvl-suite.modules', []);

        if (! is_array($configured)) {
            throw new RuntimeException('nvl-suite.modules must be an array of module boolean flags.');
        }

        $normalized = [];
        $unknownModules = [];

        foreach ($configured as $module => $enabled) {
            if (! is_string($module) || ! isset(self::MODULES[$module])) {
                $unknownModules[] = (string) $module;

                continue;
            }

            if (! is_bool($enabled)) {
                throw new RuntimeException("Suite module [{$module}] must be configured with a boolean flag.");
            }

            $normalized[$module] = $enabled;
        }

        if ($unknownModules !== []) {
            throw new RuntimeException(sprintf(
                'Unknown suite module configuration: %s.',
                implode(', ', $unknownModules),
            ));
        }

        return $normalized;
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
