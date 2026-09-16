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
        'auth' => ['data', 'support'],
        'comments' => ['data', 'filterable', 'media', 'support'],
        'content' => ['data', 'filterable', 'media', 'support', 'translatable'],
        'csv' => ['data'],
        'data' => ['support'],
        'tenancy' => ['data', 'support'],
        'filterable' => ['data'],
        'forms' => ['data', 'filterable', 'support', 'translatable'],
        'media' => ['data', 'filterable', 'support', 'translatable'],
        'mail-notifications' => ['support'],
        'metafields' => ['data', 'support', 'translatable'],
        'pages' => ['content', 'data', 'filterable', 'metafields', 'seo', 'support', 'translatable'],
        'primitives' => ['data', 'support'],
        'seo' => ['data', 'support', 'translatable'],
        'settings' => ['data', 'support', 'tenancy'],
        'support' => [],
        'taxonomy' => ['data', 'support', 'translatable'],
        'templates' => ['content', 'data', 'filterable', 'media', 'support', 'translatable'],
        'translatable' => ['data', 'support'],
        'translations' => ['data', 'filterable', 'support'],
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
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/CommentsPackageTest.php',
                    'tests/Feature/CommentsDoctorCommandTest.php',
                    'tests/Feature/CommentRichDocumentLifecycleTest.php',
                ],
            ],
            'content' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/ContentPackageTest.php',
                    'tests/Feature/ContentContractRegressionTest.php',
                ],
            ],
            'csv' => [
                'analysis_paths' => [
                    'src',
                    'tests/Type',
                ],
                'migration_tests' => [],
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
                ],
                'migration_tests' => [
                    'tests/FormsTestCase.php',
                    'tests/Feature/FormsDoctorTest.php',
                ],
            ],
            'mail-notifications' => [
                'analysis_paths' => [
                    'src',
                    'database/factories',
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/MigrationCompatibilityGuardTest.php',
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
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/PagesPackageTest.php',
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
                    'database/factories',
                    'tests/Fixtures/TestIntegerSeoOwner.php',
                    'tests/Fixtures/TestSeoOwner.php',
                    'tests/Fixtures/TestStructuredDataProvider.php',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/SeoHardeningTest.php',
                ],
            ],
            'settings' => [
                'analysis_paths' => [
                    'src',
                    'tests/Fixtures/UsesInteractsWithSettings.php',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/SettingManagerTest.php',
                    'tests/SettingsAdoptionTest.php',
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
                    'tests/Fixtures',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/TemplatesPackageTest.php',
                ],
            ],
            'translatable' => [
                'analysis_paths' => [
                    'src',
                    'tests/Support/TestTranslatableModel.php',
                    'tests/Support/TestSelfTranslatableModel.php',
                    'tests/Support/TestDomainManagedTranslatableModel.php',
                ],
                'migration_tests' => [],
            ],
            'translations' => [
                'analysis_paths' => [
                    'src',
                ],
                'migration_tests' => [
                    'tests/TestCase.php',
                    'tests/Feature/ProviderConfigurationTest.php',
                ],
            ],
        ],
    ],
];
