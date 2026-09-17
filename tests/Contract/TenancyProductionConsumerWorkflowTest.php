<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('ships the complete sealed tenancy consumer fixture and bounded public action journey', function (): void {
    $root = dirname(__DIR__, 2);
    $fixture = $root.'/tools/fixtures/tenancy-production-consumer';
    $required = [
        'app/Console/Commands/TenancyConsumerSmokeCommand.php',
        'app/Consumers/AuthMediaConsumerWorkflow.php',
        'app/Consumers/MediaOnlyConsumerWorkflow.php',
        'app/Consumers/MediaProof.php',
        'app/Jobs/TenantProbeJob.php',
        'app/Models/TenantArticle.php',
        'app/Providers/MediaOnlyTenancyConsumerServiceProvider.php',
        'app/Providers/TenancyConsumerServiceProvider.php',
        'app/Tenancy/HostMembershipAccess.php',
        'app/Tenancy/HostTenantDirectory.php',
        'app/Tenancy/TenantArticleAdoptionAdapter.php',
        'bootstrap/providers.php',
        'bootstrap/providers-media-only.php',
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

    expect($selectedModules)->toBe([
        'support',
        'data',
        'filterable',
        'translatable',
        'activity',
        'auth',
        'media',
        'tenancy',
    ])
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

it('defines sealed full and standalone media runner phases with restart and downgrade proof', function (): void {
    $root = dirname(__DIR__, 2);
    $scriptPath = $root.'/tools/run-tenancy-production-consumer.sh';
    $script = (string) file_get_contents($scriptPath);
    $qualityWorkflow = (string) file_get_contents($root.'/.github/workflows/package-quality.yml');

    expect($scriptPath)->toBeFile()->toBeExecutable()
        ->and($script)->toContain(
            'artifact_version="${NVL_CANDIDATE_VERSION:-1.99.0}"',
            'candidate_archive="${NVL_CANDIDATE_ARCHIVE:-}"',
            'cp "$candidate_archive" "$consumer_workspace/archives/"',
            '"symlink":false',
            'prepare_application auth-media',
            'prepare_application media-only',
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
            'auth_dependency_absent:$auth_dependency_absent',
        )
        ->not->toContain('--ignore-platform-reqs', 'sleep ')
        ->and($qualityWorkflow)->toContain(
            'minio/minio:RELEASE.2025-10-15T17-29-55Z',
            'TENANCY_CONSUMER_DB_CONNECTION: pgsql',
            'TENANCY_CONSUMER_CACHE_STORE: redis',
            'TENANCY_CONSUMER_MEDIA_DRIVER: s3',
            'bash tools/run-tenancy-production-consumer.sh',
        );
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

        foreach (['auth_media', 'media_only'] as $profile) {
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
    } finally {
        if (is_file($evidence)) {
            unlink($evidence);
        }
    }
});
