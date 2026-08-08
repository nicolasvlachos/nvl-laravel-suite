<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps repository-local assistant configuration ignored and untracked', function (): void {
    $root = dirname(__DIR__, 2);
    $ignore = (string) file_get_contents($root.'/.gitignore');
    $manifest = json_decode(
        file_get_contents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $localOnlyPaths = [
        '.agents',
        '.claude',
        '.mcp.json',
        'AGENTS.md',
        'CLAUDE.md',
        'boost.json',
    ];
    $ignoreRules = [
        '/.agents/',
        '/.claude/',
        '/.mcp.json',
        '/AGENTS.md',
        '/CLAUDE.md',
        '/boost.json',
    ];
    $archiveExclusions = [
        '/.agents',
        '/.claude',
        '/.mcp.json',
        '/AGENTS.md',
        '/CLAUDE.md',
        '/boost.json',
    ];

    foreach ($ignoreRules as $rule) {
        expect($ignore)->toContain($rule);
    }

    foreach ($archiveExclusions as $exclusion) {
        expect($manifest['archive']['exclude'] ?? [])->toContain($exclusion);
    }

    $trackedFiles = new Process([
        'git',
        'ls-files',
        '--',
        ...$localOnlyPaths,
    ], $root);
    $trackedFiles->setTimeout(5);
    $trackedFiles->run();

    expect($trackedFiles->isSuccessful())->toBeTrue()
        ->and(trim($trackedFiles->getOutput()))->toBe('');
});
