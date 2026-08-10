<?php

declare(strict_types=1);

/**
 * Determine whether an autoloaded symbol exists regardless of symbol kind.
 */
function consumerReadinessSymbolExists(string $symbol): bool
{
    return class_exists($symbol)
        || interface_exists($symbol)
        || trait_exists($symbol)
        || enum_exists($symbol);
}

/**
 * Resolve a GitHub-compatible Markdown heading anchor.
 */
function consumerReadinessAnchor(string $heading): string
{
    $heading = mb_strtolower(trim($heading));
    $heading = str_replace('`', '', $heading);
    $heading = preg_replace('/[^\pL\pN _-]/u', '', $heading) ?? '';

    return preg_replace('/[ _]+/u', '-', $heading) ?? '';
}

/**
 * Assert that one repository-relative evidence reference exists.
 */
function expectConsumerReadinessEvidence(string $root, string $reference): void
{
    [$relativePath, $anchor] = array_pad(explode('#', $reference, 2), 2, null);
    $path = $root.'/'.$relativePath;

    expect($relativePath)->not->toBeEmpty()
        ->and($path)->toBeFile();

    if (! is_string($anchor) || $anchor === '') {
        return;
    }

    $source = (string) file_get_contents($path);
    preg_match_all('/^#{1,6}\s+(.+)$/m', $source, $matches);
    $anchors = array_map(
        static fn (string $heading): string => consumerReadinessAnchor($heading),
        $matches[1] ?? [],
    );

    expect($anchors)->toContain($anchor);
}

it('catalogs every package and readiness decision exactly once', function (): void {
    $root = dirname(__DIR__, 2);
    $family = require $root.'/tools/package-family.php';
    $catalog = require $root.'/tools/consumer-readiness.php';
    $packages = $catalog['packages'] ?? [];
    $expectedPackages = $family['packages'];
    $actualPackages = array_keys(is_array($packages) ? $packages : []);
    $expectedStateful = $family['stateful'];
    sort($expectedPackages);
    sort($actualPackages);
    sort($expectedStateful);

    expect($catalog['version'] ?? null)->toBe(1)
        ->and($actualPackages)->toBe($expectedPackages);

    $catalogStateful = [];

    foreach ($packages as $package => $policy) {
        expect($policy)->toBeArray()
            ->and($policy['stateful'] ?? null)->toBeBool();

        if ($policy['stateful']) {
            $catalogStateful[] = $package;
        }

        $applicationApi = $policy['application_api'] ?? [];
        $symbols = $applicationApi['symbols'] ?? [];
        $directModelAccess = $applicationApi['direct_model_access'] ?? null;

        expect($symbols)->toBeArray()->not->toBeEmpty()
            ->and($directModelAccess)->toBeIn(['forbidden', 'declared_trait_query_api']);

        foreach ($symbols as $symbol) {
            expect($symbol)->toBeString()->not->toBeEmpty();
            expect(consumerReadinessSymbolExists($symbol))->toBeTrue();
        }

        expectConsumerReadinessEvidence($root, $applicationApi['documentation']);

        if ($directModelAccess === 'declared_trait_query_api') {
            expect($applicationApi['rationale'] ?? null)->toBeString()->not->toBeEmpty();
        } else {
            expect($applicationApi['rationale'] ?? null)->toBeNull();
        }

        foreach (['performance', 'media_lifecycle', 'locale_fallback', 'boundaries', 'presets', 'operations'] as $pillar) {
            $decision = $policy[$pillar] ?? [];
            $status = $decision['status'] ?? null;
            $evidence = $decision['evidence'] ?? [];

            expect($status)->toBeIn(['pass', 'not_applicable'])
                ->and($evidence)->toBeArray();

            if ($status === 'pass') {
                expect($evidence)->not->toBeEmpty()
                    ->and($decision['rationale'] ?? null)->toBeNull();

                foreach ($evidence as $reference) {
                    expectConsumerReadinessEvidence($root, $reference);
                }
            } else {
                expect($evidence)->toBe([])
                    ->and($decision['rationale'] ?? null)->toBeString()->not->toBeEmpty();
            }
        }

        $performance = $policy['performance'];
        $queryTests = $performance['query_tests'] ?? [];
        $cache = $performance['cache'] ?? [];

        expect($queryTests)->toBeArray()
            ->and($cache['mode'] ?? null)->toBeIn(['none', 'cached']);

        if ($performance['status'] === 'pass') {
            expect($queryTests)->not->toBeEmpty();

            foreach ($queryTests as $queryTest) {
                expectConsumerReadinessEvidence($root, $queryTest);
            }
        } else {
            expect($queryTests)->toBe([]);
        }

        if ($cache['mode'] === 'cached') {
            expect($cache)->toHaveKeys([
                'owner',
                'dimensions',
                'ttl',
                'invalidation',
                'isolation',
                'stampede',
            ]);
            expect(consumerReadinessSymbolExists($cache['owner']))->toBeTrue();

            foreach (['dimensions', 'invalidation'] as $key) {
                expect($cache[$key])->toBeArray()->not->toBeEmpty();
            }

            foreach (['ttl', 'isolation', 'stampede'] as $key) {
                expect($cache[$key])->toBeString()->not->toBeEmpty();
            }
        } else {
            expect($cache['rationale'] ?? null)->toBeString()->not->toBeEmpty();
        }

        $operations = $policy['operations'];
        expect($operations['adoption'] ?? null)->toBeIn([
            'application_api',
            'application_owned',
            'command',
            'documented_bridge',
            'not_applicable',
        ]);
        expectConsumerReadinessEvidence($root, $operations['documentation']);

        if ($policy['stateful']) {
            expect($operations['status'])->toBe('pass')
                ->and($operations['doctor'] ?? null)->toBeArray();
        }

        $doctor = $operations['doctor'] ?? null;

        if (is_array($doctor)) {
            $symbol = $doctor['symbol'] ?? null;
            $command = $doctor['command'] ?? null;
            expect($symbol)->toBeString()
                ->and($command)->toBeString();
            expect(class_exists($symbol))->toBeTrue();

            $signature = (new ReflectionClass($symbol))->getDefaultProperties()['signature'] ?? null;
            expect($signature)->toBeString();
            preg_match('/^\S+/', $signature, $signatureMatch);
            expect($signatureMatch[0] ?? null)->toBe($command);
        }
    }

    sort($catalogStateful);
    expect($catalogStateful)->toBe($expectedStateful);
});

it('limits declared model query APIs and built-in presets to reviewed capabilities', function (): void {
    $catalog = require dirname(__DIR__, 2).'/tools/consumer-readiness.php';
    $directModelPackages = [];
    $presetPackages = [];

    foreach ($catalog['packages'] as $package => $policy) {
        if ($policy['application_api']['direct_model_access'] === 'declared_trait_query_api') {
            $directModelPackages[] = $package;
        }

        if ($policy['presets']['status'] === 'pass') {
            $presetPackages[] = $package;
        }
    }

    sort($directModelPackages);
    sort($presetPackages);

    expect($directModelPackages)->toBe(['filterable', 'translatable'])
        ->and($presetPackages)->toBe(['content', 'media']);
});

it('keeps the rendered matrix aligned with every catalog classification', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/consumer-readiness.php';
    $document = (string) file_get_contents($root.'/docs/consumer-readiness.md');
    preg_match_all(
        '/^\| `([^`]+)` \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \| (Pass|N\/A) \|$/m',
        $document,
        $rows,
        PREG_SET_ORDER,
    );

    $rendered = [];

    foreach ($rows as $row) {
        $rendered[$row[1]] = array_slice($row, 2);
    }

    expect(array_keys($rendered))->toHaveCount(count($catalog['packages']));

    foreach ($catalog['packages'] as $package => $policy) {
        $status = static fn (array $decision): string => $decision['status'] === 'pass'
            ? 'Pass'
            : 'N/A';

        expect($rendered[$package] ?? null)->toBe([
            'Pass',
            $status($policy['performance']),
            $status($policy['media_lifecycle']),
            $status($policy['locale_fallback']),
            $status($policy['boundaries']),
            $status($policy['presets']),
            $status($policy['operations']),
        ]);
    }
});

it('rejects raw writes to tables owned by another package', function (): void {
    $root = dirname(__DIR__, 2);
    $family = require $root.'/tools/package-family.php';
    $tableOwners = [];

    foreach ($family['packages'] as $package) {
        $migrationPaths = glob($root.'/packages/nvl/'.$package.'/database/migrations/*.php') ?: [];

        foreach ($migrationPaths as $migrationPath) {
            $source = (string) file_get_contents($migrationPath);
            preg_match_all('/Schema::create\([\'\"]([^\'\"]+)[\'\"]/', $source, $matches);

            foreach ($matches[1] ?? [] as $table) {
                $tableOwners[$table] = $package;
            }
        }
    }

    expect($tableOwners)->not->toBeEmpty();

    foreach ($family['packages'] as $package) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/packages/nvl/'.$package.'/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/DB::table\([\'\"]([^\'\"]+)[\'\"]\)/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[1] ?? [] as [$table, $offset]) {
                $owner = $tableOwners[$table] ?? null;

                if (! is_string($owner) || $owner === $package) {
                    continue;
                }

                $chain = substr($source, (int) $offset, 800);
                $writes = preg_match('/->(?:insert|insertOrIgnore|upsert|update|delete)\s*\(/', $chain) === 1;

                expect($writes)->toBeFalse();
            }
        }
    }
});
