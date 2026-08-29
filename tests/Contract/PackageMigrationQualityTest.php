<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('keeps every released package migration checksum locked and parseable', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $contractRelativePath = $catalog['quality']['released_migrations_contract'] ?? null;

    expect($contractRelativePath)->toBeString()->not->toBeEmpty();

    $contractPath = $root.'/'.$contractRelativePath;

    expect($contractPath)->toBeFile();

    $contracts = json_decode(
        file_get_contents($contractPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $parser = (new ParserFactory)->createForNewestSupportedVersion();

    foreach ($contracts['packages'] ?? [] as $package => $contract) {
        foreach ($contract['migrations'] ?? [] as $relativePath => $checksum) {
            $migrationPath = $root.'/packages/nvl/'.$package.'/'.$relativePath;

            expect($migrationPath)->toBeFile()
                ->and(migrationContractChecksum($migrationPath))->toBe($checksum)
                ->and($parser->parse(file_get_contents($migrationPath)))->toBeArray();
        }
    }
});

/**
 * Hash executable migration tokens using the package-contract normalization.
 */
function migrationContractChecksum(string $path): string
{
    $normalized = '';

    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $normalized .= $token[1];

            continue;
        }

        $normalized .= $token;
    }

    return hash('sha256', $normalized);
}

it('declares executable migration evidence and database-family coverage', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $contractRelativePath = $catalog['quality']['released_migrations_contract'] ?? null;

    expect($contractRelativePath)->toBeString()->not->toBeEmpty();

    $contractPath = $root.'/'.$contractRelativePath;

    expect($contractPath)->toBeFile();

    $contracts = json_decode(
        file_get_contents($contractPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-quality.yml');
    $postgresCommands = workflowCommands($workflow['jobs']['postgresql'] ?? []);
    $mysqlCommands = workflowCommands($workflow['jobs']['mysql-family'] ?? []);

    foreach ($contracts['packages'] ?? [] as $package => $contract) {
        if (($contract['migrations'] ?? []) === []) {
            continue;
        }

        $evidence = $catalog['quality']['packages'][$package]['migration_tests'] ?? [];

        expect($evidence)->toBeArray()->not->toBeEmpty()
            ->and($catalog['database_tested'])->toContain($package)
            ->and($postgresCommands)->toContain($package)
            ->and($mysqlCommands)->toContain($package);

        foreach ($evidence as $testPath) {
            expect($root.'/packages/nvl/'.$package.'/'.$testPath)->toBeFile();
        }
    }
});

it('release-reviews the forward-only Comments document migration without changing it', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $contractPath = $root.'/'.$catalog['quality']['released_migrations_contract'];
    $contracts = json_decode(
        file_get_contents($contractPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $relativePath = 'database/migrations/2026_08_28_000001_add_comment_documents.php';
    $migrationPath = $root.'/packages/nvl/comments/'.$relativePath;
    $process = new Process([PHP_BINARY, $root.'/tools/validate-package-family.php'], $root);
    $process->setTimeout(30);
    $process->run();

    expect($contracts['packages']['comments']['migrations'][$relativePath] ?? null)
        ->toBe(migrationContractChecksum($migrationPath))
        ->and($catalog['quality']['packages']['comments']['migration_tests'] ?? [])
        ->toContain('tests/Feature/CommentRichDocumentLifecycleTest.php')
        ->and($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
