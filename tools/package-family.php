<?php

declare(strict_types=1);

/**
 * Canonical package-family metadata shared by local quality tooling.
 *
 * @return array{
 *     packages: list<string>,
 *     internal_dependencies: array<string, list<string>>,
 *     typescript_sources: list<string>,
 *     database_tested: list<string>,
 *     stateful: list<string>,
 *     optional_migrations: array<string, array{configuration: string, path: string}>,
 *     tenancy_release: array{package_count: int, profiles: list<string>, distribution_assertions: list<string>},
 *     quality: array{
 *         released_migrations_contract: string,
 *         packages: array<string, array{analysis_paths: list<string>, migration_tests: list<string>}>
 *     }
 * }
 */
return [
    'packages' => [
        'support',
        'data',
        'tenancy',
        'auth',
        'csv',
        'filterable',
        'translatable',
        'primitives',
        'settings',
        'activity',
        'taxonomy',
        'media',
        'mail-notifications',
        'content',
        'comments',
        'templates',
        'metafields',
        'pages',
        'translations',
        'seo',
        'forms',
    ],
    'internal_dependencies' => [
        'activity' => ['data', 'support', 'tenancy'],
        'auth' => ['data', 'support', 'tenancy'],
        'comments' => ['data', 'filterable', 'media', 'support', 'tenancy'],
        'content' => ['data', 'filterable', 'media', 'support', 'tenancy', 'translatable'],
        'csv' => ['data', 'tenancy'],
        'data' => ['support'],
        'tenancy' => ['data', 'support'],
        'filterable' => ['data'],
        'forms' => ['data', 'filterable', 'support', 'tenancy', 'translatable'],
        'media' => ['data', 'filterable', 'support', 'tenancy', 'translatable'],
        'mail-notifications' => ['settings', 'support', 'tenancy'],
        'metafields' => ['data', 'support', 'tenancy', 'translatable'],
        'pages' => ['content', 'data', 'filterable', 'metafields', 'seo', 'support', 'tenancy', 'translatable'],
        'primitives' => ['data', 'support'],
        'seo' => ['data', 'support', 'tenancy', 'translatable'],
        'settings' => ['data', 'support', 'tenancy'],
        'support' => [],
        'taxonomy' => ['data', 'support', 'tenancy', 'translatable'],
        'templates' => ['content', 'data', 'filterable', 'media', 'support', 'tenancy', 'translatable'],
        'translatable' => ['data', 'support', 'tenancy'],
        'translations' => ['data', 'filterable', 'support', 'tenancy'],
    ],
    'typescript_sources' => [
        'tenancy',
        'activity',
        'auth',
        'comments',
        'content',
        'csv',
        'data',
        'filterable',
        'forms',
        'mail-notifications',
        'media',
        'metafields',
        'pages',
        'primitives',
        'seo',
        'settings',
        'taxonomy',
        'templates',
        'translatable',
        'translations',
    ],
    'database_tested' => [
        'tenancy',
        'activity',
        'auth',
        'comments',
        'content',
        'filterable',
        'forms',
        'media',
        'mail-notifications',
        'metafields',
        'pages',
        'seo',
        'settings',
        'taxonomy',
        'templates',
        'translatable',
        'translations',
    ],
    'stateful' => [
        'tenancy',
        'activity',
        'auth',
        'comments',
        'content',
        'forms',
        'media',
        'mail-notifications',
        'metafields',
        'pages',
        'seo',
        'settings',
        'taxonomy',
        'templates',
        'translations',
    ],
    'optional_migrations' => [
        'tenancy' => ['configuration' => 'tenancy', 'path' => 'database/migrations/tenancy'],
    ],
    'tenancy_release' => [
        'package_count' => 21,
        'profiles' => [
            'disabled',
            'full-package-auth',
            'host-uuid-custom-principals',
            'standalone-media-no-auth',
            'standalone-taxonomy-no-auth',
        ],
        'distribution_assertions' => [
            'archive-closure',
            'provider-discovery',
            'module-config-closure',
            'source-skill-sync',
            'typescript-inputs',
            'public-contract-inputs',
            'composer-validation',
            'dependency-audit',
        ],
    ],
    'quality' => [
        'released_migrations_contract' => 'tools/package-contracts.json',
        'packages' => [
            'activity' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                    'database/seeders',
                    'database/tenancy-migrations',
                    'tests/Stubs/TestActivitySubjectWithHasModelActivity.php',
                    'tests/Stubs/TestActivityTimelineHost.php',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/ActivitySafetyTest.php',
                    'tests/Feature/ActivityConsoleCommandCoverageTest.php',
                    'tests/Tenancy/ActivityTenantTest.php',
                ],
            ],
            'auth' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/AuthDeliveryContextMigrationTest.php',
                    'tests/Feature/SchemaOwnershipTest.php',
                ],
            ],
            'comments' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/CommentsPackageTest.php',
                    'tests/Feature/CommentsDoctorCommandTest.php',
                    'tests/Feature/CommentRichDocumentLifecycleTest.php',
                    'tests/Tenancy/CommentsTenantTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
            'content' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                    'database/tenancy',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/ContentPackageTest.php',
                    'tests/Feature/ContentContractRegressionTest.php',
                    'tests/Tenancy/TenantContentTest.php',
                    'tests/Tenancy/TenantContentCompositionTest.php',
                    'tests/Tenancy/TenantAdoptionTest.php',
                ],
            ],
            'csv' => [
                'analysis_paths' => [
                    'src',
                    'tests/Type',
                ],
                'migration_tests' => [
                    'tests/Tenancy/TenantCsvBoundaryTest.php',
                    'tests/Tenancy/TenantCsvWorkerTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
            'data' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures/DataTransformFixture.php',
                ],
                'migration_tests' => [],
            ],
            'filterable' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures/FilterableRecord.php',
                ],
                'migration_tests' => [],
            ],
            'forms' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                    'database/seeders',
                    'database/tenancy-migrations',
                ],
                'migration_tests' => [
                    'tests/FormsTestCase.php',
                    'tests/Feature/FormsDoctorTest.php',
                    'tests/Tenancy/FormTenantTest.php',
                    'tests/Tenancy/PublicFormTenantHttpTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
            'mail-notifications' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                    'database/tenancy-migrations',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/MigrationCompatibilityGuardTest.php',
                    'tests/Tenancy/MailTenantTest.php',
                    'tests/Tenancy/MailTenantWorkerTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
            'media' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                    'database/seeders',
                    'tests/Stubs/TestMediaModel.php',
                ],
                'migration_tests' => [
                    'tests/MediaTestCase.php',
                    'tests/Feature/G09ImplementationTest.php',
                    'tests/Feature/MediaDoctorTest.php',
                ],
            ],
            'metafields' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                    'database/seeders',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/MetafieldsArchitectureTest.php',
                ],
            ],
            'pages' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                    'database/tenancy',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/PagesPackageTest.php',
                    'tests/Tenancy/TenantPagesTest.php',
                    'tests/Tenancy/TenantPageContextTest.php',
                    'tests/Tenancy/TenantAdoptionTest.php',
                ],
            ],
            'primitives' => [
                'analysis_paths' => [
                    'src',
                ],
                'migration_tests' => [],
            ],
            'seo' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                    'database/tenancy',
                    'database/factories',
                    'tests/Fixtures/TestIntegerSeoOwner.php',
                    'tests/Fixtures/TestSeoOwner.php',
                    'tests/Fixtures/TestStructuredDataProvider.php',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/SeoHardeningTest.php',
                    'tests/Tenancy/TenantSiteIdentityTest.php',
                    'tests/Tenancy/TenantSeoTest.php',
                    'tests/Tenancy/TenantAdoptionTest.php',
                ],
            ],
            'settings' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                    'tests/Fixtures/UsesInteractsWithSettings.php',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/SettingManagerTest.php',
                    'tests/SettingsAdoptionTest.php',
                    'tests/Tenancy/PlatformSettingsProviderBootTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
            'support' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures/ConfigurationServiceProvider.php',
                ],
                'migration_tests' => [],
            ],
            'tenancy' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TenancySupportedDatabaseTestCase.php',
                    'tests/Feature/TenantCoreSchemaTest.php',
                    'tests/Feature/TenantSupportedDatabaseTest.php',
                ],
            ],
            'taxonomy' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/TaxonomyTest.php',
                ],
            ],
            'templates' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/TemplatesPackageTest.php',
                    'tests/Tenancy/TemplatesTenantTest.php',
                    'tests/Tenancy/TemplateCatalogImportTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
            'translatable' => [
                'analysis_paths' => [
                    'src',
                    'tests/Support/TenantArticle.php',
                    'tests/Support/TenantArticleTranslation.php',
                    'tests/Support/TenantSelfEntry.php',
                    'tests/Support/TenantTranslationProbeJob.php',
                    'tests/Support/TenantMixedTranslationOwner.php',
                    'tests/Support/TenantPolymorphicTranslationChild.php',
                    'tests/Support/TestTranslatableModel.php',
                    'tests/Support/TestSelfTranslatableModel.php',
                    'tests/Support/TestDomainManagedTranslatableModel.php',
                    'tests/Fixtures/TenancyConsumerRaceCommand.php',
                    'tests/Fixtures/TenancyConsumerServiceProvider.php',
                    'tests/Fixtures/TenancyConsumerSetupCommand.php',
                ],
                'migration_tests' => [],
            ],
            'translations' => [
                'analysis_paths' => [
                    'src',
                    'database/tenancy-migrations',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/ProviderConfigurationTest.php',
                    'tests/Feature/TenantTranslationBoundaryTest.php',
                    'tests/Tenancy/AdoptionTest.php',
                ],
            ],
        ],
    ],
];
