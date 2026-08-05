<?php

declare(strict_types=1);

it('consumes Content only through its canonical public boundaries', function (): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src'),
    );
    $forbidden = [
        'Nvl\\Content\\Actions\\',
        'Nvl\\Content\\Facades\\',
        'Nvl\\Content\\Models\\',
        'Nvl\\Content\\Services\\',
    ];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        expect($source)->toBeString()->not->toContain(...$forbidden);
    }
});
