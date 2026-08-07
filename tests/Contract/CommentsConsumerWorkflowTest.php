<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('keeps Comments in the complete and Laravel 12 compatibility suites', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = commentsWorkflowDefinition($root);
    $jobs = commentsWorkflowArray($workflow, 'jobs');
    $current = commentsWorkflowArray($jobs, 'current-tests');
    $lowest = commentsWorkflowArray($jobs, 'laravel12-lowest');
    $currentStep = commentsWorkflowStep($current, 'Complete test suite');
    $lowestStep = commentsWorkflowStep($lowest, 'Compatibility tests');

    expect(commentsWorkflowString($currentStep, 'run'))->toBe('composer test')
        ->and(commentsWorkflowString($lowestStep, 'run'))->toContain(
            'composer test:integration',
            'composer test:packages',
        );
});

it('keeps the Comments sealed artifact proof on version tags', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = commentsWorkflowDefinition($root);
    $jobs = commentsWorkflowArray($workflow, 'jobs');
    $job = commentsWorkflowArray($jobs, 'archives');
    $buildStep = commentsWorkflowStep($job, 'Build and inspect all package archives');
    $allArchivesStep = commentsWorkflowStep($job, 'Install and exercise built archives');
    $buildCommand = commentsWorkflowString($buildStep, 'run');

    expect($job['if'] ?? null)->toBe("startsWith(github.ref, 'refs/tags/v')")
        ->and($buildCommand)->toContain(
            'for directory in packages/nvl/*; do',
            'php tools/inspect-package-archive.php "$archive" "$package"',
        )
        ->and(commentsWorkflowString($allArchivesStep, 'run'))->toContain(
            'composer config repositories.nvl artifact',
            'packages+=("nvl/$(basename "$directory"):$PACKAGE_VERSION")',
        );
});

it('runs the complete Comments suite against PostgreSQL', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = commentsWorkflowDefinition($root);
    $jobs = commentsWorkflowArray($workflow, 'jobs');
    $job = commentsWorkflowArray($jobs, 'postgresql');
    $services = commentsWorkflowArray($job, 'services');
    $service = commentsWorkflowArray($services, 'postgres');
    $serviceEnvironment = commentsWorkflowArray($service, 'env');
    $step = commentsWorkflowStep($job, 'Stateful package tests');
    $stepEnvironment = commentsWorkflowArray($step, 'env');
    $command = commentsWorkflowString($step, 'run');

    expect(commentsWorkflowString($job, 'name'))->toBe('PostgreSQL stateful packages')
        ->and(commentsWorkflowString($service, 'image'))->toBe('postgres:17')
        ->and($serviceEnvironment)->toBe([
            'POSTGRES_DB' => 'nvl_test_ci',
            'POSTGRES_USER' => 'nvl',
            'POSTGRES_PASSWORD' => 'nvl',
        ])
        ->and(commentsWorkflowStringList($service, 'ports'))->toBe(['5432:5432'])
        ->and($stepEnvironment)->toMatchArray([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 5432,
            'DB_DATABASE' => 'nvl_test_ci',
            'DB_USERNAME' => 'nvl',
            'DB_PASSWORD' => 'nvl',
        ])
        ->and($command)->toContain('for package in activity auth comments content');
});

it('exercises the Comments production consumer after a release candidate upgrade', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = commentsWorkflowDefinition($root, 'release-rehearsal.yml');
    $jobs = commentsWorkflowArray($workflow, 'jobs');
    $job = commentsWorkflowArray($jobs, 'upgrade-rehearsal');
    $strategy = commentsWorkflowArray($job, 'strategy');
    $matrix = commentsWorkflowArray($strategy, 'matrix');
    $step = commentsWorkflowStep($job, 'Upgrade to candidate and validate');
    $command = commentsWorkflowString($step, 'run');

    expect(commentsWorkflowStringList($matrix, 'database'))->toBe([
        'sqlite',
        'mysql',
        'pgsql',
    ])
        ->and($command)->toContain(
            'composer config repositories.nvl composer "file://$GITHUB_WORKSPACE/build/rehearsal/candidate"',
            '"$GITHUB_WORKSPACE/tools/run-comments-production-consumer.sh"',
            '/tmp/nvl-upgrade-consumer',
            'composer audit --locked --no-interaction',
        )
        ->and(substr_count(
            $command,
            'tools/run-comments-production-consumer.sh',
        ))->toBe(1);
});

it('keeps the Comments artifact and production runners isolated and complete', function (): void {
    $root = dirname(__DIR__, 2);
    $productionRunnerPath = $root.'/tools/run-comments-production-consumer.sh';
    $artifactRunnerPath = $root.'/tools/run-comments-artifact-consumer.sh';

    expect($productionRunnerPath)->toBeFile()
        ->and(is_executable($productionRunnerPath))->toBeTrue()
        ->and($artifactRunnerPath)->toBeFile()
        ->and(is_executable($artifactRunnerPath))->toBeTrue();

    $productionRunner = commentsWorkflowFileContents($productionRunnerPath);
    $artifactRunner = commentsWorkflowFileContents($artifactRunnerPath);

    expect($productionRunner)->toContain(
        'set -euo pipefail',
        'tools/fixtures/comments-production-consumer',
        'APP_ENV=production',
        'CACHE_STORE=database',
        'MEDIA_ALLOW_NOOP_SCANNER=true',
        'MEDIA_QUEUE_ENABLED=false',
        'QUEUE_CONNECTION=sync',
        'comments_artisan config:cache',
        'comments_artisan route:cache',
        'comments_artisan nvl:comments:doctor --strict --format=json',
        'comments_artisan nvl:data:types:generate',
        'comments_artisan nvl:data:types:check',
        './node_modules/.bin/tsc --noEmit -p comments-consumer-types/tsconfig.json',
        'comments_artisan migrate:rollback --force --step=999',
    )
        ->and(substr_count(
            $productionRunner,
            'comments_artisan comments-consumer:smoke --format=json',
        ))->toBe(3);

    expect($artifactRunner)->toContain(
        'set -euo pipefail',
        'if [[ $# -ne 4 ]]; then',
        '12 | 13)',
        'nvl-{comments,data,filterable,media,support,translatable}-*.zip',
        'if [[ ${#archives[@]} -ne 6 ]]; then',
        'for package in comments data filterable media support translatable; do',
        'test ! -e "$artifact_root/packages.json"',
        '"laravel/laravel:^$laravel_major.0"',
        'composer config repositories.nvl artifact "$artifact_root"',
        '"nvl/comments:$package_version"',
        '"nvl/comments"',
        '"nvl/data"',
        '"nvl/filterable"',
        '"nvl/media"',
        '"nvl/support"',
        '"nvl/translatable"',
        '($package["dist"]["type"] ?? null) !== "zip"',
        'str_starts_with($url, $artifactRoot."/")',
        'str_contains($url, $workspace)',
        '$archivePath = realpath($url)',
        '($manifest["require"]["nvl/comments"] ?? null) !== $expectedVersion',
        'isset($manifest["require"][$transitive])',
        'tools/run-comments-production-consumer.sh',
        'composer audit --locked --no-interaction',
    )
        ->not()->toContain(
            'composer config repositories.nvl composer',
            'cp "$archive_directory/packages.json"',
        );
});

it('keeps the Comments production consumer fixture representative', function (): void {
    $root = dirname(__DIR__, 2);
    $fixtureRoot = $root.'/tools/fixtures/comments-production-consumer';

    foreach ([
        'app/Comments/Authorization/ApplicationCommentAuthorization.php',
        'app/Comments/Authorization/ApplicationMediaAuthorization.php',
        'app/Comments/Authors/ApplicationCommentAuthorPresenter.php',
        'app/Comments/Http/CommentsConsumerActorResolver.php',
        'app/Comments/Probe/CommentsConsumerProbe.php',
        'app/Comments/Probe/SyntheticCommentsHttpClient.php',
        'app/Comments/Targets/ArticleCommentTargetResolver.php',
        'app/Console/Commands/CommentsConsumerSmokeCommand.php',
        'app/Models/CommentsArticle.php',
        'app/Providers/CommentsProductionServiceProvider.php',
        'bootstrap/providers.php',
        'config/comments.php',
        'database/migrations/2026_08_02_000003_create_comments_consumer_articles_table.php',
        'typescript/comments-consumer.ts',
        'typescript/tsconfig.json',
    ] as $path) {
        expect($fixtureRoot.'/'.$path)->toBeFile();
    }

    $provider = commentsWorkflowFileContents(
        $fixtureRoot.'/app/Providers/CommentsProductionServiceProvider.php',
    );
    $config = commentsWorkflowFileContents($fixtureRoot.'/config/comments.php');
    $probe = commentsWorkflowFileContents(
        $fixtureRoot.'/app/Comments/Probe/CommentsConsumerProbe.php',
    );
    $typescript = commentsWorkflowFileContents(
        $fixtureRoot.'/typescript/comments-consumer.ts',
    );
    $typescriptConfig = json_decode(
        commentsWorkflowFileContents($fixtureRoot.'/typescript/tsconfig.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($provider)->toContain(
        'CommentAuthorization::class',
        'CommentQueryScope::class',
        'MediaAuthorization::class',
        'Auth::viaRequest(',
        "'comments-consumer-public'",
        "'comments-consumer-member'",
        "'comments-consumer-management'",
        "'comments-consumer-assets'",
    )
        ->and($config)->toContain(
            "'public' => [",
            "'member' => [",
            "'management' => [",
            "'attachments' => [",
            "'store' => 'database'",
            "'allow_local_store' => false",
        )
        ->and($probe)->toContain(
            "private const string UPPER_TARGET = 'Article-A'",
            "private const string LOWER_TARGET = 'article-a'",
            '$this->assertTransportGuards($author)',
            '$this->assertExactTargetQueues(',
            '$this->approveAndReadPublicComment(',
            '$this->exerciseMemberWorkflow(',
            '$this->exerciseReportWorkflow(',
            '$this->exerciseAttachmentWorkflow(',
            '$this->assertReconciliation()',
            "['Idempotency-Key' =>",
            "['private', 'no-store', 'max-age=0']",
            "'nvl:comments:reconcile'",
        )
        ->and($typescript)->toContain(
            'satisfies Nvl.Comments.Data.Mutations.CreateCommentData',
            'satisfies Nvl.Comments.Data.Mutations.ModerateCommentData',
            'satisfies Nvl.Comments.Data.Mutations.ReportCommentData',
            'satisfies Nvl.Comments.Data.Mutations.UpdateCommentData',
            'Nvl.Comments.Data.PublicCommentData',
            'Nvl.Comments.Data.CommentManagementData',
        )
        ->and($typescriptConfig['compilerOptions']['lib'] ?? null)->toBe([
            'ES2022',
            'DOM',
        ]);
});

/**
 * Parse the package-quality workflow into a string-keyed document.
 *
 * @return array<string, mixed>
 */
function commentsWorkflowDefinition(
    string $root,
    string $filename = 'package-quality.yml',
): array {
    $workflow = Yaml::parseFile($root.'/.github/workflows/'.$filename);

    if (! is_array($workflow)) {
        throw new RuntimeException('The package-quality workflow is not a YAML mapping.');
    }

    return commentsWorkflowStringKeyedArray($workflow, 'package-quality workflow');
}

/**
 * Read one required mapping from a parsed workflow mapping.
 *
 * @param  array<string, mixed>  $mapping
 * @return array<string, mixed>
 */
function commentsWorkflowArray(array $mapping, string $key): array
{
    $value = $mapping[$key] ?? null;

    if (! is_array($value)) {
        throw new RuntimeException("The workflow key [{$key}] is not a mapping.");
    }

    return commentsWorkflowStringKeyedArray($value, "workflow key [{$key}]");
}

/**
 * Read one required list of mappings from a parsed workflow mapping.
 *
 * @param  array<string, mixed>  $mapping
 * @return list<array<string, mixed>>
 */
function commentsWorkflowArrayList(array $mapping, string $key): array
{
    $value = $mapping[$key] ?? null;

    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("The workflow key [{$key}] is not a list.");
    }

    $entries = [];

    foreach ($value as $index => $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException("Workflow entry [{$key}.{$index}] is not a mapping.");
        }

        $entries[] = commentsWorkflowStringKeyedArray(
            $entry,
            "workflow entry [{$key}.{$index}]",
        );
    }

    return $entries;
}

/**
 * Read one required list of strings from a parsed workflow mapping.
 *
 * @param  array<string, mixed>  $mapping
 * @return list<string>
 */
function commentsWorkflowStringList(array $mapping, string $key): array
{
    $value = $mapping[$key] ?? null;

    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("The workflow key [{$key}] is not a list.");
    }

    $entries = [];

    foreach ($value as $index => $entry) {
        if (! is_string($entry)) {
            throw new RuntimeException("Workflow entry [{$key}.{$index}] is not a string.");
        }

        $entries[] = $entry;
    }

    return $entries;
}

/**
 * Find one named workflow step and retain its parsed mapping type.
 *
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function commentsWorkflowStep(array $job, string $name): array
{
    foreach (commentsWorkflowArrayList($job, 'steps') as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    throw new RuntimeException("The workflow step [{$name}] is missing.");
}

/**
 * Read one required workflow string.
 *
 * @param  array<string, mixed>  $mapping
 */
function commentsWorkflowString(array $mapping, string $key): string
{
    $value = $mapping[$key] ?? null;

    if (! is_string($value)) {
        throw new RuntimeException("The workflow key [{$key}] is not a string.");
    }

    return $value;
}

/**
 * Read one required contract fixture without losing its string type.
 */
function commentsWorkflowFileContents(string $path): string
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read contract fixture [{$path}].");
    }

    return $contents;
}

/**
 * Reject non-string keys before treating a YAML mapping as associative data.
 *
 * @param  array<array-key, mixed>  $mapping
 * @return array<string, mixed>
 */
function commentsWorkflowStringKeyedArray(array $mapping, string $label): array
{
    $result = [];

    foreach ($mapping as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException("The {$label} contains a non-string key.");
        }

        $result[$key] = $value;
    }

    return $result;
}
