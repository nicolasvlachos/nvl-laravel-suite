<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('ships the complete sealed tenancy consumer fixture and bounded public action journey', function (): void {
    $root = dirname(__DIR__, 2);
    $fixture = $root.'/tools/fixtures/tenancy-production-consumer';
    $required = [
        'app/Console/Commands/TenancyConsumerSmokeCommand.php',
        'app/Console/Commands/TenancyConsumerLifecycleCommand.php',
        'app/Console/Commands/TenancyConsumerConfigurationCommand.php',
        'app/Console/Commands/TenancyConsumerRaceCommand.php',
        'app/Console/Commands/TenancyConsumerQueryPlanCommand.php',
        'app/Consumers/AuthMediaConsumerWorkflow.php',
        'app/Consumers/PublicationProof.php',
        'app/Consumers/LegacyAdoptionProof.php',
        'app/Consumers/MediaOnlyConsumerWorkflow.php',
        'app/Consumers/TaxonomyOnlyConsumerWorkflow.php',
        'app/Consumers/MediaProof.php',
        'app/Jobs/TenantProbeJob.php',
        'app/Models/TenantArticle.php',
        'app/Providers/MediaOnlyTenancyConsumerServiceProvider.php',
        'app/Providers/TenancyConsumerServiceProvider.php',
        'app/Providers/TaxonomyOnlyTenancyConsumerServiceProvider.php',
        'app/Tenancy/HostMembershipAccess.php',
        'app/Tenancy/HostTenantDirectory.php',
        'app/Tenancy/TenantArticleAdoptionAdapter.php',
        'bootstrap/providers.php',
        'bootstrap/providers-media-only.php',
        'bootstrap/providers-taxonomy-only.php',
        'legacy/adoption-manifest.json',
        'config/comments.php',
        'config/content.php',
        'config/metafields.php',
        'config/taxonomy.php',
        'config/templates.php',
        'config/media.php',
        'config/nvl-auth.php',
        'config/nvl-suite.php',
        'config/tenancy.php',
        'config/tenancy-media-only.php',
        'database/migrations/2026_09_16_190001_create_tenant_probe_tables.php',
    ];

    foreach ($required as $path) {
        expect($fixture.'/'.$path)->toBeFile();
    }

    $suite = require $fixture.'/config/nvl-suite.php';
    $selectedModules = array_keys(array_filter($suite['modules'] ?? []));
    $fullWorkflow = (string) file_get_contents($fixture.'/app/Consumers/AuthMediaConsumerWorkflow.php');
    $mediaWorkflow = (string) file_get_contents($fixture.'/app/Consumers/MediaOnlyConsumerWorkflow.php');
    $mediaProof = (string) file_get_contents($fixture.'/app/Consumers/MediaProof.php');
    $probeJob = (string) file_get_contents($fixture.'/app/Jobs/TenantProbeJob.php');
    $mediaProvider = (string) file_get_contents($fixture.'/app/Providers/MediaOnlyTenancyConsumerServiceProvider.php');
    $mediaTenancy = (string) file_get_contents($fixture.'/config/tenancy-media-only.php');

    expect($selectedModules)->toHaveCount(21)
        ->and($fullWorkflow)->toContain(
            'ProvisionTenantAction',
            'ProvisionTenantOwnerAction',
            'SynchronizePermissionCatalogAction',
            'SynchronizeRoleTemplatesAction',
            'SyncUserRolesAction',
            'ListOwnMembershipsAction',
            'ListRoleOptionsAction',
        )
        ->and($mediaProof)->toContain(
            'UploadMediaAction',
            'AttachMediaAction',
            'UpdateMediaMetadataAction',
            'TenantAssignment',
            'TenantProbeJob',
        )
        ->and($mediaWorkflow)->not->toContain('Nvl\\Auth\\')
        ->and($mediaProvider.$mediaTenancy)->not->toContain('Nvl\\Auth\\')
        ->and($fullWorkflow.$mediaProof)->not->toContain(
            "DB::table('nvl_auth_tenant_memberships')->insert",
            "DB::table('media')->insert",
            'TenantMembership::query()->create',
            'Media::query()->create',
        )
        ->and($probeJob)->toContain("DB::table('tenant_probe_observations')->insert");
});

it('covers publication lifecycle legacy matrix races and query budgets through explicit boundaries', function (): void {
    $root = dirname(__DIR__, 2);
    $fixture = $root.'/tools/fixtures/tenancy-production-consumer/app';
    $publication = (string) file_get_contents($fixture.'/Consumers/PublicationProof.php');
    $lifecycle = (string) file_get_contents($fixture.'/Console/Commands/TenancyConsumerLifecycleCommand.php');
    $configuration = (string) file_get_contents($fixture.'/Console/Commands/TenancyConsumerConfigurationCommand.php');
    $races = (string) file_get_contents($fixture.'/Console/Commands/TenancyConsumerRaceCommand.php');
    $plans = (string) file_get_contents($fixture.'/Console/Commands/TenancyConsumerQueryPlanCommand.php');

    expect($publication)->toContain(
        'CreatePageAction',
        'Content::capture',
        'CreateMetafieldDefinitionAction',
        "'referencedModelType' => 'media'",
        'CreateTermAction',
        'CreateFormEntryAction',
        'RenderStoredTemplateAction',
        'CreateRichCommentAction',
        'ActivityLog::record',
        'ScheduledMailScheduler',
        'CSVExport::make()',
        'getTemporaryUrl',
        "generate('default')",
    )->and($lifecycle)->toContain(
        'backup|adopt|suspend|cleanup|restore|verify',
        'TenantMaintenanceRunner',
        'approved_ids',
        "'retain-audit'",
        "'retain-delivery-ledger'",
        "'retain-immutable-render-history'",
        'checkpoints',
        'TENANCY_CONSUMER_RESTORE_SOURCE',
    )->not->toContain('deleteTenant', 'truncate')
        ->and($configuration)->toContain(
            'conflicting-platform-family',
            'invalid-classes',
            'invalid-families',
            'invalid-custom-tables',
            'invalid-connection-aliases',
        )
        ->and($races)->toContain(
            'last-owner',
            'grant-revoke-import',
            'slug-handle-create',
            'media-slot-completion',
            'submission-idempotency',
        )
        ->and($plans)->toContain('EXPLAIN ', 'tenant_id', "'budget'");
});

it('defines sealed full and standalone media runner phases with restart and downgrade proof', function (): void {
    $root = dirname(__DIR__, 2);
    $scriptPath = $root.'/tools/run-tenancy-production-consumer.sh';
    $script = (string) file_get_contents($scriptPath);
    $qualityWorkflow = (string) file_get_contents($root.'/.github/workflows/package-quality.yml');

    expect($scriptPath)->toBeFile()
        ->and(is_executable($scriptPath))->toBeTrue()
        ->and($script)->toContain(
            'artifact_version="${NVL_CANDIDATE_VERSION:-1.99.0}"',
            'candidate_archive="${NVL_CANDIDATE_ARCHIVE:-}"',
            'cp "$candidate_archive" "$consumer_workspace/archives/"',
            '"symlink":false',
            'prepare_application auth-media',
            'prepare_application media-only',
            'prepare_application taxonomy-only',
            'install_fixture media-only',
            'packages=(support data filterable tenancy translatable media)',
            'test ! -d vendor/nvl/auth',
            'test ! -d "$consumer_root/app/Auth"',
            'consumer_artisan "$profile" config:cache',
            'consumer_artisan "$profile" route:cache',
            'tenancy-consumer:smoke --phase=seed --format=json',
            'queue:work database',
            '--stop-when-empty',
            '--tries=1',
            '--timeout=60',
            'tenancy-consumer:smoke --phase=verify --format=json',
            'TENANCY_CONSUMER_ENABLED=false consumer_artisan',
            'unsafe_downgrade_denied:true',
            'run_configuration_matrix',
            'run_competing_operations',
            'tenancy-consumer:lifecycle cleanup',
            'tenancy-consumer:query-plans',
            'auth_dependency_absent:$auth_dependency_absent',
        )
        ->not->toContain('--ignore-platform-reqs', 'sleep ')
        ->and($qualityWorkflow)->toContain(
            'quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z',
            'TENANCY_CONSUMER_DB_CONNECTION: pgsql',
            'TENANCY_CONSUMER_CACHE_STORE: redis',
            'TENANCY_CONSUMER_MEDIA_DRIVER: s3',
            'bash tools/run-tenancy-production-consumer.sh',
        );
});

it('uses the supported application profile for the host directory matrix case', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY,
        '-r',
        'require "vendor/autoload.php"; $configuration = require "tools/fixtures/tenancy-production-consumer/config/tenancy.php"; echo json_encode(["profile" => $configuration["profile"], "directory" => $configuration["directory"]], JSON_THROW_ON_ERROR);',
    ], $root, ['TENANCY_CONSUMER_MATRIX_PROFILE' => 'host-uuid-custom-principals']);
    $process->mustRun();

    $configuration = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($configuration)->toMatchArray([
        'profile' => 'application',
        'directory' => [
            'driver' => 'host',
            'adapter' => 'App\\Tenancy\\HostTenantDirectory',
        ],
    ]);
});

it('runs the sealed consumer and asserts its actual outcomes when explicitly requested', function (): void {
    if (getenv('RUN_TENANCY_PRODUCTION_CONSUMER') !== '1') {
        test()->markTestSkipped('Set RUN_TENANCY_PRODUCTION_CONSUMER=1 for the sealed archive process proof.');
    }

    $root = dirname(__DIR__, 2);
    $evidence = tempnam(sys_get_temp_dir(), 'nvl-tenancy-evidence-');
    if (! is_string($evidence)) {
        throw new RuntimeException('Unable to allocate tenancy consumer evidence storage.');
    }

    try {
        $process = new Process(
            ['bash', $root.'/tools/run-tenancy-production-consumer.sh'],
            $root,
            ['TENANCY_CONSUMER_EVIDENCE_FILE' => $evidence],
        );
        $process->setTimeout(1800);
        $process->mustRun();

        $result = json_decode((string) file_get_contents($evidence), true, flags: JSON_THROW_ON_ERROR);
        expect($result['passed'] ?? null)->toBeTrue();

        foreach (['auth_media', 'media_only', 'taxonomy_only'] as $profile) {
            $outcome = $result['profiles'][$profile] ?? null;
            expect($outcome)->toBeArray()
                ->and($outcome['sealed_archive'] ?? null)->toBeTrue()
                ->and($outcome['config_cache'] ?? null)->toBeTrue()
                ->and($outcome['route_cache'] ?? null)->toBeTrue()
                ->and($outcome['real_worker'] ?? null)->toBeTrue()
                ->and($outcome['unsafe_downgrade_denied'] ?? null)->toBeTrue()
                ->and($outcome['verify']['passed'] ?? null)->toBeTrue()
                ->and(array_filter(
                    $outcome['verify']['checks'] ?? [],
                    static fn (mixed $passed): bool => $passed !== true,
                ))->toBe([]);
        }

        expect($result['profiles']['media_only']['auth_dependency_absent'] ?? null)->toBeTrue();
        expect($result['profiles']['taxonomy_only']['auth_dependency_absent'] ?? null)->toBeTrue()
            ->and($result['lifecycle'] ?? null)->toBeTrue()
            ->and($result['competing_processes'] ?? null)->toBeTrue()
            ->and($result['query_plans'] ?? null)->toBeTrue();
    } finally {
        if (is_file($evidence)) {
            unlink($evidence);
        }
    }
});
