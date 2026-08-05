<?php

declare(strict_types=1);

use Nvl\Translations\Actions\Entries\ListTranslationFilterOptionsAction;

/**
 * Return Translations application PHP files below a relative directory.
 *
 * @param  string  $relativePath  Relative directory below the module app directory
 * @return list<string> Absolute PHP file paths
 */
function translationsArchitecturePhpFiles(string $relativePath = ''): array
{
    $basePath = __DIR__.'/../../src'.($relativePath !== '' ? '/'.$relativePath : '');
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

test('translation controllers keep persistence and database queries outside http orchestration', function (): void {
    $violations = [];

    foreach (translationsArchitecturePhpFiles('Http/Controllers') as $file) {
        $contents = (string) file_get_contents($file);

        foreach ([
            'raw database query' => '/\bDB::/',
            'query builder creation' => '/::query\s*\(/',
            'static where query' => '/::where[A-Z]?\w*\s*\(/',
            'model save' => '/->save\s*\(/',
            'model update' => '/->update\s*\(/',
            'model delete' => '/->delete\s*\(/',
        ] as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $violations[] = str_replace(base_path().'/', '', $file).' violates '.$label;
            }
        }
    }

    expect($violations)->toBe([]);
});

test('translation app uses strict types without service locator or phpstan suppressions', function (): void {
    $violations = [];

    foreach (translationsArchitecturePhpFiles() as $file) {
        $contents = (string) file_get_contents($file);
        $relativeFile = str_replace(base_path().'/', '', $file);

        if (! str_contains($contents, 'declare(strict_types=1);')) {
            $violations[] = $relativeFile.' is missing strict types';
        }

        foreach ([
            'app class resolution' => '/\bapp\s*\([^)]*::class\)/',
            'facade make' => '/\bApp::make\s*\(/',
            'container resolve' => '/\bContainer::resolve\s*\(/',
            'resolve class' => '/\bresolve\s*\([^)]*::class\)/',
            'phpstan suppression' => '/@phpstan-ignore|phpstan-ignore-next-line|phpstan-ignore-line/',
        ] as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $violations[] = $relativeFile.' violates '.$label;
            }
        }
    }

    expect($violations)->toBe([]);
});

test('translation filter options are owned by one focused query action', function (): void {
    $reflection = new ReflectionClass(ListTranslationFilterOptionsAction::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->getMethod('execute')->isPublic())->toBeTrue();
});

test('translation console options use their declared defaults and validation', function (): void {
    $this->artisan('nvl:translations:export', ['--format' => 'invalid'])
        ->expectsOutput('Invalid --format option. Allowed values: php, json, both.')
        ->assertFailed();

    $this->artisan('nvl:translations:unused', ['--limit' => 1])
        ->expectsOutputToContain('Unused entries:')
        ->assertSuccessful();

    $this->artisan('nvl:translations:unused', ['--days' => 'invalid'])
        ->expectsOutput('The --days option must be an integer between 0 and 3650.')
        ->assertFailed();

    $this->artisan('nvl:translations:status', ['--format' => 'yaml'])
        ->expectsOutput('Invalid --format option. Allowed values: text, json.')
        ->assertFailed();
});
