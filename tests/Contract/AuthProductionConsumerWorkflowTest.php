<?php

declare(strict_types=1);

it('defines the complete Auth production consumer fixture', function (): void {
    $root = dirname(__DIR__, 2);
    $fixtureRoot = $root.'/tools/fixtures/auth-production-consumer';

    foreach ([
        'app/Auth/Activity/UserActivityMapping.php',
        'app/Auth/Authorization/AuthConsumerAccess.php',
        'app/Auth/Authorization/AuthConsumerMailReadAuthorization.php',
        'app/Auth/Authorization/AuthConsumerSettingsAuthorization.php',
        'app/Auth/AuthConsumerProbe.php',
        'app/Auth/Rbac/AuthConsumerPermissionCatalog.php',
        'app/Auth/Rbac/AuthConsumerRoleTemplates.php',
        'app/Console/Commands/AuthConsumerSmokeCommand.php',
        'app/Mail/QueuedAuthConsumerMail.php',
        'app/Models/User.php',
        'app/Providers/AuthConsumerServiceProvider.php',
        'app/Settings/consumer.settings.php',
        'bootstrap/providers.php',
        'config/activity.php',
        'config/mail-notifications.php',
        'config/nvl-auth.php',
        'config/nvl-suite.php',
        'config/settings.php',
        'resources/views/mail/auth-consumer.blade.php',
        'typescript/auth-consumer.ts',
        'typescript/tsconfig.json',
    ] as $path) {
        expect($fixtureRoot.'/'.$path)->toBeFile();
    }

    /** @var array{modules: array<string, bool>} $suite */
    $suite = require $fixtureRoot.'/config/nvl-suite.php';

    expect($suite['modules'])->toBe([
        'support' => true,
        'data' => true,
        'filterable' => false,
        'translatable' => false,
        'activity' => true,
        'auth' => true,
        'csv' => false,
        'mail-notifications' => true,
        'media' => false,
        'comments' => false,
        'content' => false,
        'metafields' => false,
        'primitives' => false,
        'seo' => false,
        'settings' => true,
        'taxonomy' => false,
        'templates' => false,
        'translations' => false,
        'forms' => false,
        'pages' => false,
    ]);

    /** @var array{scripts: array{analyse: string|list<string>, format: string, format:test: string}} $composer */
    $composer = json_decode(
        authProductionFixtureContents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $analysis = is_array($composer['scripts']['analyse'])
        ? implode("\n", $composer['scripts']['analyse'])
        : $composer['scripts']['analyse'];
    expect($analysis)->toContain('tools/fixtures/auth-production-consumer/app')
        ->and($composer['scripts']['format'])->toContain(
            'tools/fixtures/auth-production-consumer',
        )
        ->and($composer['scripts']['format:test'])->toContain(
            'tools/fixtures/auth-production-consumer',
        );
});

it('uses package Actions and explicit authorization without direct package queries', function (): void {
    $fixtureRoot = dirname(__DIR__, 2).'/tools/fixtures/auth-production-consumer';
    $provider = authProductionFixtureContents(
        $fixtureRoot.'/app/Providers/AuthConsumerServiceProvider.php',
    );
    $access = authProductionFixtureContents(
        $fixtureRoot.'/app/Auth/Authorization/AuthConsumerAccess.php',
    ).authProductionFixtureContents(
        $fixtureRoot.'/app/Auth/Authorization/AuthConsumerSettingsAuthorization.php',
    ).authProductionFixtureContents(
        $fixtureRoot.'/app/Auth/Authorization/AuthConsumerMailReadAuthorization.php',
    );
    $probe = authProductionFixtureContents(
        $fixtureRoot.'/app/Auth/AuthConsumerProbe.php',
    );
    $auth = authProductionFixtureContents($fixtureRoot.'/config/nvl-auth.php');
    $mail = authProductionFixtureContents(
        $fixtureRoot.'/config/mail-notifications.php',
    );

    expect($provider)->toContain(
        'AuthManagementAccess::class',
        'SystemMutationAccess::class',
        'SettingsAuthorization::class',
        'MailNotificationReadAuthorization::class',
        'MappingRegistry::class',
        'UserActivityMapping',
    )
        ->and($access)->toContain(
            'AuthorizationException',
            'nvl-auth.rbac.bootstrap',
            'auth-consumer.manage',
        )
        ->and($auth)->toContain(
            'User::class',
            'AuthConsumerPermissionCatalog::class',
            'AuthConsumerRoleTemplates::class',
            "'use_package_storage' => true",
        )
        ->and($mail)->toContain(
            "'consumer-user' => User::class",
            'AuthConsumerMailReadAuthorization::class',
            "'array' => 'array'",
        )
        ->and($probe)->not->toContain(
            'Role::query(',
            'Permission::query(',
            'Setting::query(',
            'MailNotification::query(',
            'ActivityLog::query(',
        )
        ->and($probe)->toContain(
            'BootstrapRbacAction',
            'CreateUserAction',
            'ListRoleOptionsAction',
            'SuggestRolesAction',
            'ListPermissionOptionsAction',
            'SuggestPermissionsAction',
            'ListPermissionGroupsAction',
            'ListRoleCatalogAction',
            'ListPermissionCatalogAction',
            'CheckRoleNameAvailabilityAction',
            'ResolveRoleIdentifiersAction',
            'ResolvePermissionIdentifiersAction',
            'ShowRoleAnalyticsAction',
            'SyncUserRolesAction',
            'GetSettingAction',
            'SetSettingAction',
            'ResetSettingAction',
            'SettingSubjectReferenceData',
            'recordForSubjectReference',
            'TrackingLifecycle',
            'Mail::to',
            'QueuedAuthConsumerMail',
            'ListMailNotificationsAction',
            'GetMailNotificationStatisticsAction',
            'failedOnly: true',
            'assertDeniedActor',
        )
        ->and($probe)->not->toContain('->assignRole(');
});

it('compiles the generated Auth and Settings transport contracts', function (): void {
    $fixtureRoot = dirname(__DIR__, 2).'/tools/fixtures/auth-production-consumer';
    $typescript = authProductionFixtureContents(
        $fixtureRoot.'/typescript/auth-consumer.ts',
    );
    /** @var array{compilerOptions: array<string, mixed>, include: list<string>} $configuration */
    $configuration = json_decode(
        authProductionFixtureContents($fixtureRoot.'/typescript/tsconfig.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($typescript)->toContain(
        'Nvl.Auth.Data.Display.RoleOptionData',
        'Nvl.Auth.Data.Display.PermissionOptionData',
        'Nvl.Auth.Data.Display.RoleAnalyticsData',
        'satisfies Nvl.Settings.Data.SettingMutationData',
        'Nvl.Settings.Data.SettingValueData',
    )
        ->and($configuration['compilerOptions']['strict'] ?? null)->toBeTrue()
        ->and($configuration['compilerOptions']['skipLibCheck'] ?? null)->toBeFalse()
        ->and($configuration['include'])->toContain(
            '../resources/js/types/**/*.d.ts',
            './auth-consumer.ts',
        );
});

it('runs both Auth migration ownership modes from a sealed artifact', function (): void {
    $root = dirname(__DIR__, 2);
    $runnerPath = $root.'/tools/run-auth-production-consumer.sh';

    expect($runnerPath)->toBeFile()
        ->and(is_executable($runnerPath))->toBeTrue();

    $runner = authProductionFixtureContents($runnerPath);
    $skillsPosition = mb_strpos(
        $runner,
        'auth_consumer_artisan nvl:suite:skills:publish --format=json',
    );
    $typesPosition = mb_strpos(
        $runner,
        'auth_consumer_artisan nvl:data:types:check',
    );
    $auditPosition = mb_strpos(
        $runner,
        'auth_consumer_artisan nvl:suite:consumer-audit --strict --format=json',
    );
    $smokePosition = mb_strpos(
        $runner,
        'auth_consumer_artisan auth-consumer:smoke --format=json',
    );
    $workerPosition = mb_strpos(
        $runner,
        'auth_consumer_artisan queue:work --stop-when-empty',
    );
    $queueVerificationPosition = mb_strpos(
        $runner,
        'auth_consumer_artisan auth-consumer:smoke --verify-queued-mail --format=json',
    );

    expect($runner)->toContain(
        'set -euo pipefail',
        'mktemp -d',
        'composer archive',
        'create-project',
        '--dry-run',
        '"symlink":false',
        'test ! -L vendor/nvl/laravel-suite',
        'package_owned',
        'application_owned',
        'vendor:publish --tag=auth-migrations',
        'vendor:publish --tag=settings-migrations',
        'vendor:publish --tag=activity-migrations',
        'vendor:publish --tag=mail-notifications-migrations',
        'auth_consumer_artisan config:cache',
        'auth_consumer_artisan route:cache',
        'auth_consumer_artisan nvl:suite:skills:publish --format=json',
        'auth_consumer_artisan nvl:suite:doctor --strict --production --format=json',
        'auth_consumer_artisan nvl:suite:consumer-audit --strict --format=json',
        'auth_consumer_artisan nvl:data:types:generate',
        'auth_consumer_artisan nvl:data:types:check',
        'auth_consumer_artisan auth-consumer:smoke --format=json',
        'auth_consumer_artisan auth-consumer:smoke --verify-queued-mail --format=json',
        'QUEUE_CONNECTION=database',
        'queue:work --stop-when-empty',
        './node_modules/.bin/tsc --noEmit -p auth-consumer-types/tsconfig.json',
    )
        ->and($runner)->not->toContain(
            '"symlink":true',
            'QUEUE_CONNECTION=sync',
            '--ignore-platform-reqs',
            'consumer-audit-ignore',
        );

    expect($skillsPosition)->toBeInt()
        ->and($typesPosition)->toBeInt()
        ->and($auditPosition)->toBeInt()
        ->and($smokePosition)->toBeInt()
        ->and($workerPosition)->toBeInt()
        ->and($queueVerificationPosition)->toBeInt()
        ->and($skillsPosition)->toBeLessThan($auditPosition)
        ->and($typesPosition)->toBeLessThan($auditPosition)
        ->and($smokePosition)->toBeLessThan($workerPosition)
        ->and($workerPosition)->toBeLessThan($queueVerificationPosition);
});

/** Read one required fixture file. */
function authProductionFixtureContents(string $path): string
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read Auth production fixture [{$path}].");
    }

    return $contents;
}
