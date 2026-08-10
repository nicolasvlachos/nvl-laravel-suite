<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Symfony\Component\Filesystem\Path;

it('registers every tracked publish tag with safe materializable paths', function (): void {
    $root = dirname(__DIR__, 2);
    $contract = json_decode(
        (string) file_get_contents($root.'/tools/package-contracts.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $expectedTags = $contract['suite']['publish_tags'] ?? [];

    foreach ($contract['packages'] ?? [] as $package) {
        array_push($expectedTags, ...($package['publish_tags'] ?? []));
    }

    $expectedTags = array_values(array_unique($expectedTags));
    sort($expectedTags);
    $registeredGroups = ServiceProvider::publishableGroups();
    sort($registeredGroups);

    expect(array_diff($expectedTags, $registeredGroups))->toBe([]);

    foreach ($expectedTags as $tag) {
        $paths = ServiceProvider::pathsToPublish(group: $tag);

        expect($paths)->not->toBeEmpty("Publish tag [{$tag}] has no paths.")
            ->and(array_values($paths))->toHaveCount(count(array_unique(array_values($paths))));

        foreach ($paths as $source => $destination) {
            $canonicalSource = realpath($source);
            $canonicalDestination = Path::canonicalize($destination);

            expect($canonicalSource)->not->toBeFalse("Publish source [{$source}] does not exist.")
                ->and(str_starts_with((string) $canonicalSource, $root.'/'))->toBeTrue(
                    "Publish source [{$source}] is outside the suite.",
                )
                ->and(str_starts_with($canonicalDestination, $root.'/'))->toBeTrue(
                    "Publish destination [{$destination}] escapes the consumer root.",
                );

            if (is_dir($source)) {
                $files = iterator_to_array(new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                ));

                expect($files)->not->toBeEmpty("Publish directory [{$source}] is empty.");
            }
        }
    }
});

it('registers every stateful migration directory as timestamp-aware', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $publishableMigrations = array_map(
        static fn (string $path): string|false => realpath($path),
        ServiceProvider::publishableMigrationPaths(),
    );

    foreach ($catalog['stateful'] as $package) {
        expect($publishableMigrations)->toContain(
            realpath($root.'/packages/nvl/'.$package.'/database/migrations'),
        );
    }
});
