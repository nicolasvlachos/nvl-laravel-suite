<?php

declare(strict_types=1);

use Nvl\Activity\Console\Commands\ActivityDoctorCommand;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Auth\Actions\Invitations\FindActiveInvitationAction;
use Nvl\Auth\Actions\Invitations\ListInvitationProjectionsAction;
use Nvl\Auth\Actions\Invitations\RecordInvitationDeliveryOutcomeAction;
use Nvl\Auth\Actions\Rbac\ShowRoleAnalyticsAction;
use Nvl\Auth\Actions\Users\ListUsersAction;
use Nvl\Auth\Console\Commands\AuthDoctorCommand;
use Nvl\Auth\Contracts\AuthManagementAccess;
use Nvl\Auth\Definitions\Tables\AuthTables;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Actions\ListCommentsAction;
use Nvl\Comments\Console\CommentsDoctorCommand;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Content\Actions\FindContentBlockByKeyAction;
use Nvl\Content\Actions\FindContentPlacementAction;
use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction;
use Nvl\Content\Actions\ReorderContentPlacementsAction;
use Nvl\Content\Actions\ReplaceContentPlacementAction;
use Nvl\Content\Actions\ResolveContentScopesAction;
use Nvl\Content\Console\ContentDoctorCommand;
use Nvl\Content\Content;
use Nvl\Content\Definitions\Tables\ContentTables;
use Nvl\Csv\Services\CSVExport;
use Nvl\Csv\Services\CSVImport;
use Nvl\Data\Services\GeneratedTypesGenerator;
use Nvl\Data\Traits\DataTransform;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Forms\Actions\Form\ListFormsAction;
use Nvl\Forms\Console\Commands\FormsDoctorCommand;
use Nvl\Forms\Contracts\CreateFormContract;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\MailNotifications\Actions\GetMailNotificationStatisticsAction;
use Nvl\MailNotifications\Actions\ListMailNotificationsAction;
use Nvl\MailNotifications\Console\Commands\MailNotificationsDoctorCommand;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\ValueObjects\MailNotificationAggregate;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\Media\Actions\ClearOwnerMediaSlotAction;
use Nvl\Media\Actions\CopyOwnerMediaSlotAction;
use Nvl\Media\Actions\GetOwnerMediaSlotAction;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Console\Commands\MediaDoctorCommand;
use Nvl\Media\Console\Commands\PruneMediaOwnerSlotOperationsCommand;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\MediaLibrary;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Console\Commands\MetafieldDoctorCommand;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Pages\Actions\CheckPageKeyAvailabilityAction;
use Nvl\Pages\Actions\FindPageByKeyAction;
use Nvl\Pages\Actions\GetNavigationAction;
use Nvl\Pages\Actions\GetPageEditorBootstrapAction;
use Nvl\Pages\Actions\GetPagePublicationProjectionAction;
use Nvl\Pages\Actions\ListPageEditorSummariesAction;
use Nvl\Pages\Actions\ListPageOptionsAction;
use Nvl\Pages\Actions\ListPublicChildPagesAction;
use Nvl\Pages\Actions\ResolvePageAction;
use Nvl\Pages\Console\PagesDoctorCommand;
use Nvl\Pages\Data\PageEditorBootstrapData;
use Nvl\Pages\Data\PageEditorSummaryData;
use Nvl\Pages\Data\PageKeyAvailabilityData;
use Nvl\Pages\Data\PageOptionData;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Enums\PublicChildPageOrder;
use Nvl\Primitives\ValueObjects\LocaleCode;
use Nvl\Primitives\ValueObjects\Money;
use Nvl\Seo\Actions\GetOwnerSeoProfileAction;
use Nvl\Seo\Actions\GetOwnerSeoRevisionAction;
use Nvl\Seo\Actions\GetSeoProfileAction;
use Nvl\Seo\Actions\ListOwnerSeoProfilesAction;
use Nvl\Seo\Console\SeoDoctorCommand;
use Nvl\Seo\Data\SeoOwnerRevisionData;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Services\SeoHeadRenderer;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Commands\DoctorCommand;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Data\SettingSubjectReferenceData;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Services\SettingCache;
use Nvl\Support\Contracts\ResponseCode;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Taxonomy\Actions\ResolveTermsAction;
use Nvl\Taxonomy\Commands\TaxonomyDoctorCommand;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Services\TaxonomyTree;
use Nvl\Templates\Actions\ListTemplatesAction;
use Nvl\Templates\Actions\RenderTemplateAction;
use Nvl\Templates\Console\TemplatesDoctorCommand;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Translatable\Console\Commands\TranslatableDoctorCommand;
use Nvl\Translatable\Services\TranslationWriter;
use Nvl\Translatable\Translatable;
use Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Console\Commands\TranslationsDoctorCommand;
use Nvl\Translations\Data\TranslationCatalogStatisticsData;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Services\TranslationEntryFilterSchema;
use Nvl\Translations\Services\TranslationScanService;

/**
 * Build one passing consumer-readiness classification.
 *
 * @param  list<string>  $evidence
 * @return array{status: 'pass', evidence: list<string>, rationale: null}
 */
$pass = static fn (array $evidence): array => [
    'status' => 'pass',
    'evidence' => $evidence,
    'rationale' => null,
];

/**
 * Build one justified not-applicable consumer-readiness classification.
 *
 * @return array{status: 'not_applicable', evidence: list<string>, rationale: string}
 */
$notApplicable = static fn (string $rationale): array => [
    'status' => 'not_applicable',
    'evidence' => [],
    'rationale' => $rationale,
];

/**
 * Canonical consumer-readiness evidence for every package in the suite.
 *
 * Evidence references use repository-relative paths and optional Markdown anchors.
 * The Contract suite verifies package coverage, symbols, commands, paths, anchors,
 * direct-model exceptions, query evidence, cache decisions, and N/A rationales.
 *
 * @return array{
 *     version: int,
 *     consumer_boundary: array{
 *         allowed: string,
 *         compatibility_1x: string,
 *         forbidden: string,
 *         exceptions: string
 *     },
 *     runtime_guardrails: array{
 *         table_definitions: array<string, class-string>,
 *         management_actions: array<string, list<class-string|string>>
 *     },
 *     packages: array<string, array<string, mixed>>
 * }
 */
return [
    'version' => 1,
    'consumer_boundary' => [
        'allowed' => 'Actions, explicit services, contracts, DTOs, enums, owner traits, and documented identity/result models.',
        'compatibility_1x' => 'Consumer-initiated package model queries and relation aggregates remain supported only where already documented.',
        'forbidden' => 'Consumer writes through package models, builders, raw tables, pivots, or storage paths.',
        'exceptions' => 'Filterable consumer builders, Translatable opted-in scopes, adoption migrations, and documented legacy bridges.',
    ],
    'runtime_guardrails' => [
        'table_definitions' => [
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
        ],
        'management_actions' => [
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
        ],
    ],
    'packages' => [
        'support' => [
            'stateful' => false,
            'application_api' => [
                'symbols' => [BusinessException::class, ResponseCode::class],
                'direct_model_access' => 'not_applicable',
                'rationale' => null,
                'documentation' => 'packages/nvl/support/README.md#purpose',
            ],
            'performance' => [
                ...$notApplicable('Support is a transport-neutral exception and response-code utility with no persistence reads.'),
                'query_tests' => [],
                'cache' => ['mode' => 'none', 'rationale' => 'Pure value and exception contracts have no repeated storage read to cache.'],
            ],
            'media_lifecycle' => $notApplicable('Support does not own or associate media.'),
            'locale_fallback' => $notApplicable('Support owns transport-neutral failures, not localized model content.'),
            'boundaries' => $notApplicable('Support does not persist Content, Metafields, or Translatable data.'),
            'presets' => $notApplicable('Response codes and exceptions are consumer vocabulary, not package semantic presets.'),
            'operations' => [
                ...$notApplicable('Support is stateless and has no package schema or runtime invariants requiring a Doctor.'),
                'doctor' => null,
                'adoption' => 'not_applicable',
                'documentation' => 'packages/nvl/support/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'data' => [
            'stateful' => false,
            'application_api' => [
                'symbols' => [DataTransform::class, GeneratedTypesGenerator::class],
                'direct_model_access' => 'not_applicable',
                'rationale' => null,
                'documentation' => 'packages/nvl/data/README.md#dto-and-persistence-transforms',
            ],
            'performance' => [
                ...$notApplicable('Data transforms DTOs and generated artifacts without owning normalized database reads.'),
                'query_tests' => [],
                'cache' => ['mode' => 'none', 'rationale' => 'Generation is explicit build work and does not use a runtime read cache.'],
            ],
            'media_lifecycle' => $notApplicable('Data does not own or associate media.'),
            'locale_fallback' => $notApplicable('Data generates contracts and does not resolve localized model content.'),
            'boundaries' => $notApplicable('Data supplies DTO mechanics without owning domain persistence.'),
            'presets' => $notApplicable('DTO generation is utility-level behavior and package presets would invent consumer semantics.'),
            'operations' => [
                ...$notApplicable('Data is stateless; its type-check commands are build diagnostics rather than schema Doctors.'),
                'doctor' => null,
                'adoption' => 'not_applicable',
                'documentation' => 'packages/nvl/data/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'auth' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    ListUsersAction::class,
                    ShowRoleAnalyticsAction::class,
                    ListInvitationProjectionsAction::class,
                    FindActiveInvitationAction::class,
                    RecordInvitationDeliveryOutcomeAction::class,
                    AuthManagementAccess::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/auth/README.md#consumer-application-apis',
            ],
            'performance' => [
                ...$pass([
                    'packages/nvl/auth/README.md#rbac-consumer-reads-and-analytics',
                    'packages/nvl/auth/README.md#invitation-consumer-reads-and-delivery-outcomes',
                ]),
                'query_tests' => [
                    'packages/nvl/auth/tests/Feature/PrincipalManagementTest.php',
                    'packages/nvl/auth/tests/Feature/RbacManagementTest.php',
                    'packages/nvl/auth/tests/Feature/InvitationLifecycleTest.php',
                ],
                'cache' => ['mode' => 'none', 'rationale' => 'Authorization-sensitive principal, RBAC, and invitation reads are bounded and uncached so lifecycle changes and revocations are immediately visible.'],
            ],
            'media_lifecycle' => $notApplicable('Auth does not own media associations.'),
            'locale_fallback' => $notApplicable('Auth owns identity state; UI strings remain Laravel translations.'),
            'boundaries' => $notApplicable('Auth does not own Content, Metafields, or Translatable persistence.'),
            'presets' => $notApplicable('Roles and permissions are application business vocabulary supplied through providers.'),
            'operations' => [
                ...$pass(['packages/nvl/auth/README.md#migration-ownership-modes', 'packages/nvl/auth/UPGRADING.md#schema-ownership']),
                'doctor' => ['symbol' => AuthDoctorCommand::class, 'command' => 'nvl:auth:doctor'],
                'adoption' => 'command',
                'documentation' => 'packages/nvl/auth/UPGRADING.md#existing-first-party-users',
            ],
        ],
        'csv' => [
            'stateful' => false,
            'application_api' => [
                'symbols' => [CSVImport::class, CSVExport::class],
                'direct_model_access' => 'not_applicable',
                'rationale' => null,
                'documentation' => 'packages/nvl/csv/README.md#purpose',
            ],
            'performance' => [
                ...$pass(['packages/nvl/csv/README.md#security-and-operational-boundaries']),
                'query_tests' => ['packages/nvl/csv/tests/Feature/CsvExportTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Imports and exports are streaming or chunked operations; caching would duplicate source data and obscure freshness.'],
            ],
            'media_lifecycle' => $notApplicable('CSV processes caller-owned streams and does not own Media records.'),
            'locale_fallback' => $notApplicable('CSV transforms tabular values and does not resolve localized model rows.'),
            'boundaries' => $notApplicable('CSV is a transport utility and owns no domain tables.'),
            'presets' => $notApplicable('Column mappings and transforms are consumer-specific data vocabulary.'),
            'operations' => [
                ...$notApplicable('CSV is stateless; consumers own source files, destinations, and any adoption workflow.'),
                'doctor' => null,
                'adoption' => 'not_applicable',
                'documentation' => 'packages/nvl/csv/UPGRADING.md#migrating-from-applibcsv-to-10',
            ],
        ],
        'filterable' => [
            'stateful' => false,
            'application_api' => [
                'symbols' => [Filterable::class, FilterSet::class],
                'direct_model_access' => 'explicit_exception',
                'rationale' => 'Filterable intentionally makes an allowlisted model trait and typed FilterSet the public query-composition contract; it owns no package model tables.',
                'documentation' => 'packages/nvl/filterable/README.md#model-trait',
            ],
            'performance' => [
                ...$pass(['packages/nvl/filterable/README.md#declare-a-schema']),
                'query_tests' => ['packages/nvl/filterable/tests/Feature/FilterableTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Filterable composes caller-owned queries and cannot safely own result-cache identity or invalidation.'],
            ],
            'media_lifecycle' => $notApplicable('Filterable owns query composition, not media lifecycle.'),
            'locale_fallback' => $notApplicable('Filterable validates query criteria and delegates locale behavior to owning packages.'),
            'boundaries' => $notApplicable('Filterable owns no domain persistence and cannot cross package ownership boundaries.'),
            'presets' => $notApplicable('Filter definitions describe consumer schemas and must remain explicit allowlists.'),
            'operations' => [
                ...$notApplicable('Filterable is stateless and owns no schema or runtime registry requiring a Doctor.'),
                'doctor' => null,
                'adoption' => 'not_applicable',
                'documentation' => 'packages/nvl/filterable/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'translatable' => [
            'stateful' => false,
            'application_api' => [
                'symbols' => [Translatable::class, TranslationWriter::class],
                'direct_model_access' => 'explicit_exception',
                'rationale' => 'Translatable intentionally exposes typed traits, definitions, scopes, and model helpers on consumer or domain-owned models; domain mutation workflows remain package-owned Actions.',
                'documentation' => 'packages/nvl/translatable/README.md#common-model-api',
            ],
            'performance' => [
                ...$pass(['packages/nvl/translatable/README.md#queries']),
                'query_tests' => ['packages/nvl/translatable/tests/Feature/TranslatableTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Translation rows are transactionally mutable and locale-sensitive; eager loading is deterministic without a cross-request cache.'],
            ],
            'media_lifecycle' => $notApplicable('Translatable resolves localized fields and does not own media files or associations.'),
            'locale_fallback' => $pass(['packages/nvl/translatable/README.md#fallback-policies', 'packages/nvl/translatable/tests/Feature/TranslatableTest.php']),
            'boundaries' => $pass(['packages/nvl/translatable/README.md#purpose', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Locale policy is configuration, while translated fields are declared by each owning domain.'),
            'operations' => [
                ...$pass(['packages/nvl/translatable/README.md#why-schema-generation-is-intentionally-absent', 'packages/nvl/translatable/README.md#gather-and-diagnose']),
                'doctor' => ['symbol' => TranslatableDoctorCommand::class, 'command' => 'nvl:translatable:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/translatable/UPGRADING.md#adopting-typed-translation-definitions',
            ],
        ],
        'primitives' => [
            'stateful' => false,
            'application_api' => [
                'symbols' => [Money::class, LocaleCode::class],
                'direct_model_access' => 'not_applicable',
                'rationale' => null,
                'documentation' => 'packages/nvl/primitives/README.md#purpose-and-boundaries',
            ],
            'performance' => [
                ...$notApplicable('Primitives are immutable value objects, casts, and validation rules without normalized reads.'),
                'query_tests' => [],
                'cache' => ['mode' => 'none', 'rationale' => 'Immutable value operations are local and do not benefit from cross-request caching.'],
            ],
            'media_lifecycle' => $notApplicable('Primitives does not own media.'),
            'locale_fallback' => $notApplicable('LocaleCode validates identity but does not resolve localized content.'),
            'boundaries' => $notApplicable('Primitives owns value semantics and no domain persistence.'),
            'presets' => $notApplicable('Currency, country, and locale catalogs are standards-based references, not opinionated domain presets.'),
            'operations' => [
                ...$notApplicable('Primitives is stateless and requires no schema Doctor or data adoption command.'),
                'doctor' => null,
                'adoption' => 'not_applicable',
                'documentation' => 'packages/nvl/primitives/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'settings' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    SettingRepository::class,
                    SetSettingAction::class,
                    SettingChanged::class,
                    SettingSubjectReferenceData::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/settings/README.md#setting-change-subject-reference',
            ],
            'performance' => [
                ...$pass(['packages/nvl/settings/README.md#database-caching-and-adoption']),
                'query_tests' => ['packages/nvl/settings/tests/SettingManagerTest.php'],
                'cache' => [
                    'mode' => 'cached',
                    'owner' => SettingCache::class,
                    'dimensions' => ['configured store', 'configured versioned key'],
                    'ttl' => 'forever until after-commit invalidation',
                    'invalidation' => ['setting mutation after commit', 'explicit clear'],
                    'isolation' => 'Values are global by canonical namespace/scope/key; actor and locale are not dimensions.',
                    'stampede' => 'A miss may repeat one bounded table read; writes invalidate after commit and no stale value is served.',
                ],
            ],
            'media_lifecycle' => $notApplicable('Settings does not own media associations.'),
            'locale_fallback' => $notApplicable('Settings values are typed configuration; localized model content belongs to Translatable.'),
            'boundaries' => $notApplicable('Settings owns global configuration and not Content, Metafields, or translation rows.'),
            'presets' => $notApplicable('Setting definitions are application configuration vocabulary supplied by source providers.'),
            'operations' => [
                ...$pass(['packages/nvl/settings/README.md#database-caching-and-adoption']),
                'doctor' => ['symbol' => DoctorCommand::class, 'command' => 'nvl:settings:doctor'],
                'adoption' => 'command',
                'documentation' => 'packages/nvl/settings/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'activity' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [ActivityLog::class, ActivityReadService::class, ActivitySubjectReference::class],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/activity/README.md#bounded-subject-references-and-event-filters',
            ],
            'performance' => [
                ...$pass([
                    'packages/nvl/activity/README.md#timeline-limits',
                    'packages/nvl/activity/README.md#bounded-subject-references-and-event-filters',
                ]),
                'query_tests' => [
                    'packages/nvl/activity/tests/Feature/ActivityTimelineReadTest.php',
                    'packages/nvl/activity/tests/Feature/ActivityBehaviorTest.php',
                ],
                'cache' => ['mode' => 'none', 'rationale' => 'Audit timelines are actor-scoped, append-sensitive, and bounded; caching risks stale security evidence.'],
            ],
            'media_lifecycle' => $notApplicable('Activity records references and snapshots but does not own media lifecycle.'),
            'locale_fallback' => $notApplicable('Activity headline localization uses Laravel string translations, not localized model rows.'),
            'boundaries' => $notApplicable('Activity records events and does not persist Content, Metafields, or translation rows.'),
            'presets' => $notApplicable('Activity mappings and retention classes are application policy, not reusable semantic presets.'),
            'operations' => [
                ...$pass(['packages/nvl/activity/README.md#database-adoption', 'packages/nvl/activity/README.md#retention-and-operations']),
                'doctor' => ['symbol' => ActivityDoctorCommand::class, 'command' => 'nvl:activity:doctor'],
                'adoption' => 'documented_bridge',
                'documentation' => 'packages/nvl/activity/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'taxonomy' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [ResolveTermsAction::class, TaxonomyTree::class],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/taxonomy/README.md#register-vocabularies-and-owners',
            ],
            'performance' => [
                ...$pass(['packages/nvl/taxonomy/README.md#authorization-caching-and-failures']),
                'query_tests' => ['packages/nvl/taxonomy/tests/TaxonomyTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Trees and attachments are bounded and mutation-sensitive; cache use is limited to locks, not read results.'],
            ],
            'media_lifecycle' => $notApplicable('Taxonomy does not own media files or associations.'),
            'locale_fallback' => $pass(['packages/nvl/taxonomy/README.md#central-translation-management']),
            'boundaries' => $pass(['packages/nvl/taxonomy/README.md#central-translation-management', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Vocabulary and term semantics belong to the consuming application.'),
            'operations' => [
                ...$pass(['packages/nvl/taxonomy/README.md#database-and-adoption', 'packages/nvl/taxonomy/README.md#commands']),
                'doctor' => ['symbol' => TaxonomyDoctorCommand::class, 'command' => 'nvl:taxonomy:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/taxonomy/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'media' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    MediaLibrary::class,
                    MediaQueryService::class,
                    GetOwnerMediaSlotAction::class,
                    ReplaceOwnerMediaSlotAction::class,
                    ClearOwnerMediaSlotAction::class,
                    CopyOwnerMediaSlotAction::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/media/README.md#owner-slot-workflows',
            ],
            'performance' => [
                ...$pass([
                    'packages/nvl/media/README.md#retrieval-and-lifecycle',
                    'packages/nvl/media/README.md#owner-slot-workflows',
                ]),
                'query_tests' => [
                    'tests/Feature/Integration/CrossPackageIntegrationTest.php',
                ],
                'cache' => [
                    'mode' => 'cached',
                    'owner' => MediaFileExistence::class,
                    'dimensions' => ['disk', 'canonical object path'],
                    'ttl' => 'media.cache_ttl seconds',
                    'invalidation' => ['upload', 'replace', 'relocate', 'delete', 'variation mutation'],
                    'isolation' => 'The key is storage identity; actor and locale remain authorization/presentation concerns outside the existence result.',
                    'stampede' => 'Idempotent existence probes may repeat on a miss; the short TTL avoids a blocking global lock around storage.',
                ],
            ],
            'media_lifecycle' => $pass(['packages/nvl/media/README.md#owner-slot-workflows', 'packages/nvl/media/README.md#retrieval-and-lifecycle', 'packages/nvl/media/README.md#operations', 'packages/nvl/media/tests/Feature/ActionsTest.php', 'packages/nvl/media/tests/Feature/MediaOwnerSlotWorkflowTest.php']),
            'locale_fallback' => $pass(['packages/nvl/media/README.md#localized-metadata']),
            'boundaries' => $pass(['packages/nvl/media/README.md#purpose-and-boundaries', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $pass(['packages/nvl/media/README.md#variations-and-optimization', 'packages/nvl/media/tests/Unit/MediaConfiguredVariationServiceTest.php']),
            'operations' => [
                ...$pass(['packages/nvl/media/README.md#database-schema-and-adoption', 'packages/nvl/media/README.md#owner-slot-workflows', 'packages/nvl/media/docs/commands.md#nvlmediaowner-slotsprune', 'packages/nvl/media/UPGRADING.md#upgrading-to-the-production-hardened-1x-release']),
                'doctor' => ['symbol' => MediaDoctorCommand::class, 'command' => 'nvl:media:doctor'],
                'prune' => ['symbol' => PruneMediaOwnerSlotOperationsCommand::class, 'command' => 'nvl:media:owner-slots:prune'],
                'adoption' => 'command',
                'documentation' => 'packages/nvl/media/README.md#owner-slot-workflows',
            ],
        ],
        'mail-notifications' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    ListMailNotificationsAction::class,
                    GetMailNotificationStatisticsAction::class,
                    MailNotificationAggregate::class,
                    TrackingContext::class,
                    TrackingLifecycle::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/mail-notifications/README.md#administrative-delivery-reads',
            ],
            'performance' => [
                ...$pass(['packages/nvl/mail-notifications/README.md#administrative-delivery-reads']),
                'query_tests' => ['packages/nvl/mail-notifications/tests/Feature/MailNotificationAdministrationTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Delivery state is privacy- and actor-sensitive and changes asynchronously; reads are paginated and intentionally fresh.'],
            ],
            'media_lifecycle' => $notApplicable('Mail Notifications tracks delivery and does not own media assets.'),
            'locale_fallback' => $notApplicable('Mail copy uses Laravel translations or host Mailables, not Translatable model rows.'),
            'boundaries' => $notApplicable('Mail Notifications owns delivery history only.'),
            'presets' => $notApplicable('Provider and message policy are explicit consumer configuration, not package content presets.'),
            'operations' => [
                ...$pass(['packages/nvl/mail-notifications/README.md#requirements-and-installation', 'packages/nvl/mail-notifications/UPGRADING.md#adopting-a-legacy-tracker']),
                'doctor' => ['symbol' => MailNotificationsDoctorCommand::class, 'command' => 'nvl:mail-notifications:doctor'],
                'adoption' => 'command',
                'documentation' => 'packages/nvl/mail-notifications/UPGRADING.md#adopting-a-legacy-tracker',
            ],
        ],
        'content' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    Content::class,
                    FindContentBlockByKeyAction::class,
                    FindContentPlacementAction::class,
                    GetOwnerContentEditorAction::class,
                    ListOwnerContentPlacementSummariesAction::class,
                    ReorderContentPlacementsAction::class,
                    ReplaceContentPlacementAction::class,
                    ResolveContentScopesAction::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/content/README.md#editor-projections',
            ],
            'performance' => [
                ...$pass([
                    'packages/nvl/content/README.md#editor-projections',
                    'packages/nvl/content/README.md#placement-editor-workflows',
                    'packages/nvl/content/README.md#operational-guidance',
                ]),
                'query_tests' => ['packages/nvl/content/tests/Feature/ContentContractRegressionTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Content reads are locale-, scope-, publication-, and actor-sensitive; bounded eager loading avoids invalidation ambiguity.'],
            ],
            'media_lifecycle' => $pass(['packages/nvl/content/README.md#media-and-references', 'docs/consumer-readiness.md#media-lifecycle']),
            'locale_fallback' => $pass(['packages/nvl/content/README.md#localization']),
            'boundaries' => $pass(['packages/nvl/content/README.md#architecture-and-boundaries', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $pass(['packages/nvl/content/README.md#semantic-rich-content-presets', 'packages/nvl/content/tests/Feature/ContentBoundaryContractTest.php']),
            'operations' => [
                ...$pass(['packages/nvl/content/README.md#installation', 'packages/nvl/content/README.md#evolving-definitions']),
                'doctor' => ['symbol' => ContentDoctorCommand::class, 'command' => 'nvl:content:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/content/UPGRADING.md#10',
            ],
        ],
        'comments' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    ListCommentsAction::class,
                    FindLatestTargetCommentAction::class,
                    CommentSelectorData::class,
                    HasComments::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/comments/README.md#latest-target-comment-read',
            ],
            'performance' => [
                ...$pass(['packages/nvl/comments/README.md#filtering-and-pagination']),
                'query_tests' => ['packages/nvl/comments/tests/Feature/CommentsV1ApiProjectionTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Visibility, moderation, reactions, and attachment projections are actor-sensitive and intentionally fresh.'],
            ],
            'media_lifecycle' => $pass(['packages/nvl/comments/README.md#attachments']),
            'locale_fallback' => $notApplicable('Comments are authored records and do not use package-managed localized variants.'),
            'boundaries' => $notApplicable('Comments delegates attachments to Media and owns no Content, Metafields, or translation rows.'),
            'presets' => $notApplicable('Audience and moderation policy are consumer configuration, not semantic content presets.'),
            'operations' => [
                ...$pass(['packages/nvl/comments/README.md#persistence', 'packages/nvl/comments/README.md#adoption-and-privacy']),
                'doctor' => ['symbol' => CommentsDoctorCommand::class, 'command' => 'nvl:comments:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/comments/UPGRADING.md#schema-changes',
            ],
        ],
        'templates' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [RenderTemplateAction::class, ListTemplatesAction::class],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/templates/README.md#purpose-and-boundaries',
            ],
            'performance' => [
                ...$pass(['packages/nvl/templates/README.md#stored-definitions']),
                'query_tests' => ['packages/nvl/templates/tests/Feature/TemplatesPackageTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Stored definitions, versions, assignments, and render status are bounded and mutation-sensitive; rendered artifacts own their own lifecycle.'],
            ],
            'media_lifecycle' => $pass(['packages/nvl/templates/README.md#content-and-media-composition', 'docs/consumer-readiness.md#media-lifecycle']),
            'locale_fallback' => $pass(['packages/nvl/templates/README.md#stored-definitions']),
            'boundaries' => $pass(['packages/nvl/templates/README.md#purpose-and-boundaries', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Template definitions are consumer business documents; reusable content and image semantics come from Content and Media.'),
            'operations' => [
                ...$pass(['packages/nvl/templates/README.md#upgrade-and-adoption']),
                'doctor' => ['symbol' => TemplatesDoctorCommand::class, 'command' => 'nvl:templates:doctor'],
                'adoption' => 'command',
                'documentation' => 'packages/nvl/templates/README.md#upgrade-and-adoption',
            ],
        ],
        'metafields' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    ListAuthorizedOwnerMetafieldsAction::class,
                    ListOwnerMetafieldsAction::class,
                    SetMetafieldAction::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/metafields/README.md#querying',
            ],
            'performance' => [
                ...$pass(['packages/nvl/metafields/README.md#querying']),
                'query_tests' => ['packages/nvl/metafields/tests/Feature/MetafieldConsumerWorkflowTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Typed owner values and localized definitions are mutable and bounded; no measured cross-request cache benefit justifies invalidation complexity.'],
            ],
            'media_lifecycle' => $notApplicable('Metafields may reference registered owners but does not own Media files or associations.'),
            'locale_fallback' => $pass(['packages/nvl/metafields/README.md#definition-localization']),
            'boundaries' => $pass(['packages/nvl/metafields/README.md#purpose', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Metafield definitions are application-specific custom attribute vocabulary.'),
            'operations' => [
                ...$pass(['packages/nvl/metafields/README.md#database-and-adoption']),
                'doctor' => ['symbol' => MetafieldDoctorCommand::class, 'command' => 'nvl:metafields:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/metafields/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'pages' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    CheckPageKeyAvailabilityAction::class,
                    FindPageByKeyAction::class,
                    GetPageEditorBootstrapAction::class,
                    GetNavigationAction::class,
                    GetPagePublicationProjectionAction::class,
                    ListPageEditorSummariesAction::class,
                    ListPageOptionsAction::class,
                    ListPublicChildPagesAction::class,
                    PageEditorBootstrapData::class,
                    PageEditorSummaryData::class,
                    PageKeyAvailabilityData::class,
                    PageOptionData::class,
                    PublicChildPageOrder::class,
                    ResolvePageAction::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/pages/README.md#editor-and-publication-projections',
            ],
            'performance' => [
                ...$pass([
                    'packages/nvl/pages/README.md#bounded-page-reads',
                    'packages/nvl/pages/README.md#editor-and-publication-projections',
                    'packages/nvl/pages/README.md#navigation-preview-and-apis',
                ]),
                'query_tests' => ['packages/nvl/pages/tests/Feature/PagesPackageTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Page resolution and navigation depend on locale, publication, hierarchy, and dynamic resource admission; reads remain bounded and fresh.'],
            ],
            'media_lifecycle' => $notApplicable('Pages composes Content and SEO but does not directly own media files.'),
            'locale_fallback' => $pass(['packages/nvl/pages/README.md#first-working-page']),
            'boundaries' => $pass(['packages/nvl/pages/README.md#purpose-and-boundaries', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Page types and navigation are consumer business vocabulary; Content supplies reusable semantic fields.'),
            'operations' => [
                ...$pass(['packages/nvl/pages/README.md#requirements-and-installation', 'packages/nvl/pages/README.md#commands-and-operations']),
                'doctor' => ['symbol' => PagesDoctorCommand::class, 'command' => 'nvl:pages:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/pages/UPGRADING.md#to-10',
            ],
        ],
        'translations' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    GetTranslationCatalogStatisticsAction::class,
                    ImportTranslationsAction::class,
                    TranslationCatalogStatisticsData::class,
                    TranslationEntryFilterSchema::class,
                    TranslationScanService::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/translations/README.md#catalog-statistics-and-shared-filters',
            ],
            'performance' => [
                ...$pass(['packages/nvl/translations/README.md#catalog-statistics-and-shared-filters']),
                'query_tests' => ['packages/nvl/translations/tests/Feature/TranslationsConsumerContractsTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Catalog scans and imports are explicit bounded operations; editable database rows and generated files must not be hidden by a runtime result cache.'],
            ],
            'media_lifecycle' => $notApplicable('Translations owns UI string catalogs and no media lifecycle.'),
            'locale_fallback' => $notApplicable('Translations manages Laravel UI strings; model-content fallback belongs exclusively to Translatable.'),
            'boundaries' => $pass(['packages/nvl/translations/README.md#purpose-and-boundary', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Translation namespaces and source locations are application-owned string vocabulary.'),
            'operations' => [
                ...$pass(['packages/nvl/translations/README.md#requirements-and-installation', 'packages/nvl/translations/README.md#operational-workflow']),
                'doctor' => ['symbol' => TranslationsDoctorCommand::class, 'command' => 'nvl:translations:doctor'],
                'adoption' => 'command',
                'documentation' => 'packages/nvl/translations/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'seo' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [
                    GetSeoProfileAction::class,
                    GetOwnerSeoProfileAction::class,
                    GetOwnerSeoRevisionAction::class,
                    ListOwnerSeoProfilesAction::class,
                    SeoOwnerRevisionData::class,
                    SeoHeadRenderer::class,
                ],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/seo/README.md#owner-centric-profile-reads',
            ],
            'performance' => [
                ...$pass([
                    'packages/nvl/seo/README.md#owner-centric-profile-reads',
                    'packages/nvl/seo/README.md#sitemaps',
                ]),
                'query_tests' => [
                    'packages/nvl/seo/tests/Feature/SeoConsumerContractsTest.php',
                    'tests/Feature/Integration/CrossPackageIntegrationTest.php',
                ],
                'cache' => [
                    'mode' => 'cached',
                    'owner' => SitemapCache::class,
                    'dimensions' => ['site base URL', 'normalized scope', 'monotonic version'],
                    'ttl' => 'seo.sitemap.cache_seconds',
                    'invalidation' => ['SEO profile mutation after commit', 'explicit sitemap clear'],
                    'isolation' => 'Scope and origin are key dimensions; sitemap output is public and has no actor dimension.',
                    'stampede' => 'SitemapGenerator requires an atomic-lock-capable store for positive TTLs and publishes only complete artifact manifests.',
                ],
            ],
            'media_lifecycle' => $notApplicable('SEO resolves social images through Media APIs and does not own their lifecycle.'),
            'locale_fallback' => $pass(['packages/nvl/seo/README.md#localized-reads-and-centralized-discovery']),
            'boundaries' => $pass(['packages/nvl/seo/README.md#purpose-and-boundaries', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('SEO profiles and structured-data providers express consumer-specific site semantics.'),
            'operations' => [
                ...$pass(['packages/nvl/seo/README.md#requirements-and-installation', 'packages/nvl/seo/README.md#production-checklist']),
                'doctor' => ['symbol' => SeoDoctorCommand::class, 'command' => 'nvl:seo:doctor'],
                'adoption' => 'application_api',
                'documentation' => 'packages/nvl/seo/UPGRADING.md#upgrading-to-10',
            ],
        ],
        'forms' => [
            'stateful' => true,
            'application_api' => [
                'symbols' => [ListFormsAction::class, CreateFormContract::class],
                'direct_model_access' => 'compatibility_1x',
                'rationale' => null,
                'documentation' => 'packages/nvl/forms/README.md#mutate-safely',
            ],
            'performance' => [
                ...$pass(['packages/nvl/forms/README.md#public-render-and-schema-contracts']),
                'query_tests' => ['packages/nvl/forms/tests/Feature/Actions/SearchFormsActionTest.php'],
                'cache' => ['mode' => 'none', 'rationale' => 'Form definitions, fields, public admission, and entry privacy are mutable and request-sensitive; reads are bounded and intentionally fresh.'],
            ],
            'media_lifecycle' => $notApplicable('Forms owns definitions and entries but no media lifecycle.'),
            'locale_fallback' => $pass(['packages/nvl/forms/README.md#first-working-form']),
            'boundaries' => $pass(['packages/nvl/forms/README.md#purpose', 'docs/consumer-readiness.md#ownership-boundaries']),
            'presets' => $notApplicable('Form fields and workflows are consumer business vocabulary and remain explicit definitions.'),
            'operations' => [
                ...$pass(['packages/nvl/forms/README.md#requirements-and-installation', 'packages/nvl/forms/README.md#commands']),
                'doctor' => ['symbol' => FormsDoctorCommand::class, 'command' => 'nvl:forms:doctor'],
                'adoption' => 'application_owned',
                'documentation' => 'packages/nvl/forms/UPGRADING.md#upgrading-to-10',
            ],
        ],
    ],
];
