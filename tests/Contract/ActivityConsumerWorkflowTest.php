<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('keeps the Activity source consumer proof isolated and complete', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $job = $workflow['jobs']['standalone-consumers'] ?? null;
    $bootstrapStep = collect($job['steps'] ?? [])->firstWhere(
        'name',
        'Create clean consumer',
    );
    $bootstrapCommand = is_array($bootstrapStep) ? ($bootstrapStep['run'] ?? null) : null;
    $step = collect($job['steps'] ?? [])->firstWhere(
        'name',
        'Exercise Activity clean consumer',
    );
    $command = is_array($step) ? ($step['run'] ?? null) : null;

    expect($job)->toBeArray()
        ->and($job['strategy']['matrix']['include'] ?? null)->toContain([
            'laravel' => '12',
            'package' => 'activity',
        ])
        ->and($bootstrapCommand)->toBeString()->toContain(
            'if [ "${{ matrix.package }}" = "activity" ]; then',
            'export DB_QUEUE_RETRY_AFTER=960',
        )
        ->and($step)->toBeArray()
        ->and($step['if'] ?? null)->toBe("matrix.package == 'activity'")
        ->and($command)->toBeString()
        ->toContain(
            'tools/fixtures/activity-production-consumer/app/.',
            'tools/fixtures/activity-production-consumer/config/activity.php',
            'tools/fixtures/activity-production-consumer/bootstrap/providers.php',
            'create_activity_consumer_articles_table.php',
            'create_activity_consumer_activity_log_table.php',
            'QUEUE_CONNECTION=database',
            'DB_QUEUE_RETRY_AFTER=960',
            'NVL_ACTIVITY_CUSTOM_STORAGE=true',
            'touch database/activity-consumer.sqlite',
            'activity_artisan config:cache',
            'activity_artisan route:cache',
            'activity_artisan nvl:activity:doctor --strict --format=json',
            'activity_custom_artisan nvl:activity:doctor --strict --format=json',
            'activity_custom_artisan migrate:rollback --force --step=999',
            'activity-consumer:smoke --format=json',
        );

    expect(substr_count($command, 'activity-consumer:smoke --format=json'))
        ->toBeGreaterThanOrEqual(6)
        ->and(substr_count($command, 'nvl:activity:doctor --strict --format=json'))
        ->toBeGreaterThanOrEqual(4);
});

it('installs and exercises Activity from a relocated real artifact subset', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');

    expect($workflow)->toBeArray();

    $job = $workflow['jobs']['archives'] ?? null;
    $allArchivesStep = collect($job['steps'] ?? [])->firstWhere(
        'name',
        'Install and exercise built archives',
    );
    $allArchivesCommand = is_array($allArchivesStep) ? ($allArchivesStep['run'] ?? null) : null;
    $step = collect($job['steps'] ?? [])->firstWhere(
        'name',
        'Exercise relocated Activity artifacts',
    );
    $command = is_array($step) ? ($step['run'] ?? null) : null;

    expect($job)->toBeArray()
        ->and($step)->toBeArray()
        ->and($allArchivesCommand)->toBeString()->toContain(
            'export QUEUE_CONNECTION=database',
            'export DB_QUEUE_RETRY_AFTER=960',
        )
        ->and($command)->toBeString()
        ->toContain(
            'nvl-{activity,data,support}-*.zip',
            'test ! -e /tmp/nvl-activity-artifacts/packages.json',
            'composer config repositories.nvl artifact /tmp/nvl-activity-artifacts',
            '"nvl/activity:$PACKAGE_VERSION"',
            '["nvl/activity", "nvl/data", "nvl/support"]',
            'str_starts_with($url, "/tmp/nvl-activity-artifacts/")',
            '$workspace = getenv("GITHUB_WORKSPACE")',
            'is_string($workspace) && $workspace !== "" && str_contains($url, $workspace)',
            'isset($manifest["require"]["nvl/data"])',
            'tools/fixtures/activity-production-consumer/app/.',
            'archive_activity_artisan config:cache',
            'archive_activity_artisan route:cache',
            'archive_activity_artisan nvl:activity:doctor --strict --format=json',
            'archive_activity_custom_artisan nvl:activity:doctor --strict --format=json',
            'touch database/activity-consumer.sqlite',
            'DB_QUEUE_RETRY_AFTER=960',
        )
        ->not->toContain(
            'composer config repositories.nvl composer',
            'cp "$GITHUB_WORKSPACE/build/archives/packages.json"',
        );

    expect(substr_count($command, 'activity-consumer:smoke --format=json'))
        ->toBeGreaterThanOrEqual(5);
});

it('keeps the Activity production consumer fixture representative', function (): void {
    $root = dirname(__DIR__, 2);
    $fixtureRoot = $root.'/tools/fixtures/activity-production-consumer';

    foreach ([
        'app/Activity/ArticleActivityMapping.php',
        'app/Console/Commands/ActivityConsumerSmokeCommand.php',
        'app/Http/Middleware/AuthenticateActivityConsumer.php',
        'app/Models/ActivityArticle.php',
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
