<?php

declare(strict_types=1);

it('consumes Content only through its canonical public boundaries', function (): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src'),
    );
    $forbidden = [
        'Nvl\\Content\\Facades\\',
        'Nvl\\Content\\Models\\',
        'Nvl\\Content\\Services\\',
    ];
    $allowedActions = [
        'GetOwnerContentEditorAction',
        'ListOwnerContentPlacementSummariesAction',
    ];
    $usedActions = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        expect($source)->toBeString();

        foreach ($forbidden as $namespace) {
            expect($source)->not->toContain($namespace);
        }

        preg_match_all(
            '/Nvl\\\\Content\\\\Actions\\\\([A-Za-z0-9_]+)/',
            $source,
            $matches,
        );

        foreach ($matches[1] as $action) {
            expect($action)->toBeIn($allowedActions);
            $usedActions[] = $action;
        }
    }

    sort($usedActions);

    expect(array_values(array_unique($usedActions)))->toBe($allowedActions);
});

it('consumes privileged SEO profiles only through authorized Actions', function (): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src/Actions'),
    );
    $allowedActions = [
        'GetOwnerSeoProfileAction',
        'ListOwnerSeoProfilesAction',
    ];
    $usedActions = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        expect($source)->toBeString()->not->toContain('Nvl\\Seo\\Models\\');
        preg_match_all(
            '/Nvl\\\\Seo\\\\Actions\\\\([A-Za-z0-9_]+)/',
            $source,
            $matches,
        );

        foreach ($matches[1] as $action) {
            expect($action)->toBeIn($allowedActions);
            $usedActions[] = $action;
        }
    }

    sort($usedActions);

    expect(array_values(array_unique($usedActions)))->toBe($allowedActions);
});
