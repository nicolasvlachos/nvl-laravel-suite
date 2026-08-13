<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Nvl\Suite\Support\SuiteModuleCatalog;

it('keeps one adoption matrix aligned with the runtime module catalog', function (): void {
    $root = dirname(__DIR__, 2);
    $document = (string) file_get_contents($root.'/docs/adoption-matrix.md');
    $family = require $root.'/tools/package-family.php';
    $configuration = require $root.'/config/nvl-suite.php';
    $catalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => $configuration,
    ]));
    preg_match_all('/^\| `([^`]+)` \|(.+)$/m', $document, $matches, PREG_SET_ORDER);
    $rows = [];

    foreach ($matches as $match) {
        $rows[$match[1]] = $match[0];
    }

    expect(array_keys($rows))->toHaveCount(20);

    foreach ($catalog->modules() as $module => $definition) {
        expect($rows)->toHaveKey($module);

        $row = $rows[$module];
        $migrationConfig = $definition['migration']['config'];

        if (is_string($migrationConfig)) {
            expect($row)->toContain($migrationConfig);
        }

        expect($row)->toContain($definition['doctor'] ?? 'N/A')
            ->toContain($definition['typescript'] ? 'Yes' : 'No');

        foreach ($definition['schedules'] as $schedule) {
            expect($row)->toContain($schedule['command']);
        }
    }

    $catalogModules = array_keys($catalog->modules());
    $familyModules = $family['packages'];
    sort($catalogModules);
    sort($familyModules);

    expect($catalogModules)->toBe($familyModules);
});

it('documents every installation profile and suite diagnostic command', function (): void {
    $root = dirname(__DIR__, 2);
    $profiles = (string) file_get_contents($root.'/docs/installation-profiles.md');
    $readme = (string) file_get_contents($root.'/README.md');
    $catalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => require $root.'/config/nvl-suite.php',
    ]));

    foreach ($catalog->profiles() as $profile => $_definition) {
        expect($profiles)->toContain("--profile={$profile}");
    }

    expect($profiles)->toContain(
        'nvl:suite:configuration --format=json',
        'nvl:suite:doctor --production --strict --format=json',
    )->and($readme)->toContain(
        'docs/installation-profiles.md',
        'docs/adoption-matrix.md',
        'nvl:suite:configuration',
        'nvl:suite:doctor --production --strict',
    );
});
