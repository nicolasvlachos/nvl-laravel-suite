<?php

declare(strict_types=1);

it('consumes Content only through its canonical public boundaries', function (): void {
    $sourceRoot = realpath(__DIR__.'/../src');
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot),
    );
    $forbidden = [
        'Nvl\\Content\\Actions\\',
        'Nvl\\Content\\Facades\\',
        'Nvl\\Content\\Models\\',
        'Nvl\\Content\\Services\\',
        'Nvl\\Content\\Validation\\',
    ];
    $adoptionImports = [
        'Actions/AdoptTemplatesAction.php' => [
            'Nvl\\Content\\Actions\\CreateContentBlockAction',
            'Nvl\\Content\\Actions\\PublishContentBlockAction',
            'Nvl\\Content\\Actions\\UpdateContentBlockAction',
            'Nvl\\Content\\Models\\ContentBlock',
        ],
        'Services/TemplateAdoptionManifest.php' => [
            'Nvl\\Content\\Services\\ContentDefinitionRegistry',
            'Nvl\\Content\\Services\\ContentLocalePolicy',
            'Nvl\\Content\\Services\\ContentPatch',
            'Nvl\\Content\\Services\\ContentScopeRegistry',
            'Nvl\\Content\\Validation\\ContentValueValidator',
        ],
    ];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        $relativePath = substr($file->getPathname(), strlen($sourceRoot) + 1);

        foreach ($adoptionImports[$relativePath] ?? [] as $import) {
            $source = str_replace('use '.$import.';', '', $source);
        }

        foreach ($forbidden as $namespace) {
            expect($source)->not->toContain($namespace);
        }
    }
});
