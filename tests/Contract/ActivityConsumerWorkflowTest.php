<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('keeps Activity in the complete and PostgreSQL routine suites', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $current = activityWorkflowCommands($workflow['jobs']['current-tests'] ?? []);
    $lowest = activityWorkflowCommands($workflow['jobs']['laravel13-lowest'] ?? []);
    $postgresJob = $workflow['jobs']['postgresql'] ?? [];
    $postgres = activityWorkflowCommands($postgresJob);
    $statefulStep = collect($postgresJob['steps'] ?? [])->firstWhere('name', 'Stateful package tests');

    expect($current)->toContain('composer test')
        ->and($lowest)->toContain(
            '"laravel/framework:^13.0"',
            'composer test:packages',
        )
        ->and($postgres)->toContain('for package in activity auth comments content')
        ->and($statefulStep['env']['DB_CONNECTION'] ?? null)->toBe('pgsql');
});

/**
 * Return every shell command declared by an Activity-related workflow job.
 *
 * @param  array<string, mixed>  $job
 */
function activityWorkflowCommands(array $job): string
{
    return collect($job['steps'] ?? [])
        ->pluck('run')
        ->filter(static fn (mixed $command): bool => is_string($command))
        ->implode("\n");
}

it('installs Activity from the tagged suite archive', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-release.yml');

    expect($workflow)->toBeArray();

    $job = $workflow['jobs']['archive'] ?? null;
    $buildStep = collect($job['steps'] ?? [])->firstWhere('name', 'Build and inspect the suite archive');
    $installStep = collect($job['steps'] ?? [])->firstWhere('name', 'Install and exercise the suite archive');
    $buildCommand = is_array($buildStep) ? ($buildStep['run'] ?? null) : null;
    $installCommand = is_array($installStep) ? ($installStep['run'] ?? null) : null;
    $releaseProvider = $root.'/tools/fixtures/suite-release-consumer/app/Providers/AppServiceProvider.php';

    expect($job)->toBeArray()
        ->and($buildCommand)->toBeString()->toContain(
            'COMPOSER_ROOT_VERSION="$PACKAGE_VERSION" composer archive',
            '.name == "nvl/laravel-suite"',
        )
        ->and($installCommand)->toBeString()->toContain(
            '"nvl/laravel-suite:$PACKAGE_VERSION"',
            'composer config repositories.nvl "$repository_config"',
            'test ! -L vendor/nvl/laravel-suite',
            'tools/fixtures/suite-release-consumer/app/Providers/AppServiceProvider.php',
            'export QUEUE_CONNECTION=database',
            'export DB_QUEUE_RETRY_AFTER=960',
            'QUEUE_CONNECTION=database DB_QUEUE_RETRY_AFTER=960',
            'php artisan config:cache',
            'php artisan route:cache',
        )
        ->not->toContain(
            'packages+=("nvl/$(basename "$directory"):$PACKAGE_VERSION")',
            'composer config repositories.nvl composer',
            'QUEUE_CONNECTION=sync',
        )
        ->and($releaseProvider)->toBeFile()
        ->and(file_get_contents($releaseProvider))->toContain(
            "Config::set('taxonomy.owners.users', User::class)",
            "Config::set('taxonomy.taxonomies.category.allowed_owners', ['users'])",
            "Config::set('taxonomy.taxonomies.tag.allowed_owners', ['users'])",
        );
});

it('keeps the Activity production consumer fixture representative', function (): void {
    $root = dirname(__DIR__, 2);
    $fixtureRoot = $root.'/tools/fixtures/activity-production-consumer';

    foreach ([
        'app/Activity/ArticleActivityMapping.php',
        'app/Console/Commands/ActivityConsumerSmokeCommand.php',
        'app/Http/Middleware/AuthenticateActivityConsumer.php',
        'app/Models/ActivityArticle.php',
        'app/Models/User.php',
        'app/Providers/ActivityProductionServiceProvider.php',
        'bootstrap/providers.php',
        'config/activity.php',
        'database/migrations/2026_08_02_000001_create_activity_consumer_articles_table.php',
        'database/custom-storage-migrations/2026_08_02_000002_create_activity_consumer_activity_log_table.php',
    ] as $path) {
        expect($fixtureRoot.'/'.$path)->toBeFile();
    }

    $mapping = file_get_contents($fixtureRoot.'/app/Activity/ArticleActivityMapping.php');
    $middleware = file_get_contents($fixtureRoot.'/app/Http/Middleware/AuthenticateActivityConsumer.php');
    $model = file_get_contents($fixtureRoot.'/app/Models/ActivityArticle.php');
    $provider = file_get_contents($fixtureRoot.'/app/Providers/ActivityProductionServiceProvider.php');
    $config = file_get_contents($fixtureRoot.'/config/activity.php');
    $smoke = file_get_contents($fixtureRoot.'/app/Console/Commands/ActivityConsumerSmokeCommand.php');
    $customMigration = file_get_contents(
        $fixtureRoot.'/database/custom-storage-migrations/2026_08_02_000002_create_activity_consumer_activity_log_table.php',
    );

    expect($mapping)->toBeString()->toContain(
        'implements ActivityMapping',
        "->logOnly(['title', 'status'])",
        "'article.published' => ':actor published this :subject on :value.'",
    )
        ->and($middleware)->toBeString()->toContain(
            "header('X-Activity-Consumer-User')",
            'Auth::setUser($user)',
            'setUserResolver',
        )
        ->and($model)->toBeString()->toContain(
            'implements MergesActivity',
            'use HasModelActivity;',
            'use MergesActivityTimeline;',
        )
        ->and($provider)->toBeString()->toContain(
            "config('activity.storage.connection') !== 'activity_consumer'",
            "Config::set('database.connections.activity_consumer'",
            '$activityMappings->register',
            "Gate::define('activity.view'",
            "'activity.timeline'",
            "Gate::define('activity.purge'",
        )
        ->and($config)->toBeString()->toContain(
            "env('NVL_ACTIVITY_CUSTOM_STORAGE', false)",
            "'enabled' => ! \$usesCustomStorage",
            "'connection' => \$usesCustomStorage ? 'activity_consumer' : null",
            "? 'activity_consumer_activity_log'",
            "'management_middleware' => [AuthenticateActivityConsumer::class]",
            "'timeline_subjects' => [ActivityArticle::class]",
            "'search_attributes' => ['name']",
            "'allowed_purge_options' => [90]",
            "'ignored_attributes' => ['updated_at']",
        )
        ->and($smoke)->toBeString()->toContain(
            'ActivityRecorder::record(',
            "config('activity.routes.management_middleware') === [AuthenticateActivityConsumer::class]",
            "config('activity.retention.allowed_purge_options') === [90]",
            "config('queue.connections.database.retry_after')",
            'PurgeActivityLogsJob::TIMEOUT_SECONDS + 60',
            "event: 'article.internal_reviewed'",
            'ActivityVisibility::Hidden',
            'Auth::forgetUser()',
            '$unauthorized[\'status\'] === 401',
            '$events === $expectedEvents',
            '$indexTotal === 4',
            '$indexEvents === $expectedEvents',
            'buildActivityTimeline()',
            'buildActivityTimeline(2)',
            'Artisan::call(\'queue:work\'',
            "'--queue' => 'maintenance'",
            '$jobs === [[90, false], [90, true]]',
            "Schema::hasTable('failed_jobs')",
            "'/api/v1/activities?perPage=10'",
            "'/api/v1/activities/causers/suggestions?q=Activity&limit=10'",
            "'/api/v1/activities/purge'",
            "'/api/v1/activities/purge-system'",
            '! Schema::hasTable(ActivityLog::DEFAULT_TABLE)',
        )
        ->and($customMigration)->toBeString()->toContain(
            "private const string CONNECTION_NAME = 'activity_consumer'",
            "private const string TABLE_NAME = 'activity_consumer_activity_log'",
            'Schema::connection(self::CONNECTION_NAME)->create',
            '$table->uuid(\'id\')->primary()',
            '$table->index([\'created_at\', \'id\'])',
        );
});
