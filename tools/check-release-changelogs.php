<?php

declare(strict_types=1);

/**
 * Validate release notes for the suite and every changed package.
 *
 * @param  list<string>  $changedPackages
 * @return list<string>
 */
function releaseChangelogErrors(string $root, string $version, array $changedPackages): array
{
    $errors = [];
    $catalog = require $root.'/tools/package-family.php';

    if (! is_array($catalog) || ! isset($catalog['packages']) || ! is_array($catalog['packages'])) {
        return ['The package catalog does not contain a package list.'];
    }

    $knownPackages = [];

    foreach ($catalog['packages'] as $package) {
        if (! is_string($package) || trim($package) === '') {
            return ['The package catalog contains an invalid package name.'];
        }

        $knownPackages[] = $package;
    }

    $unknownPackages = array_values(array_diff($changedPackages, $knownPackages));

    if ($unknownPackages !== []) {
        $errors[] = 'Unknown changed package(s): '.implode(', ', $unknownPackages).'.';
    }

    $changelogs = ['suite' => $root.'/CHANGELOG.md'];

    foreach (array_values(array_intersect($changedPackages, $knownPackages)) as $package) {
        $changelogs[$package] = $root.'/packages/nvl/'.$package.'/CHANGELOG.md';
    }

    foreach ($changelogs as $name => $path) {
        if (! is_file($path)) {
            $errors[] = "Release changelog [{$name}] is missing at [{$path}].";

            continue;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            $errors[] = "Release changelog [{$name}] could not be read.";

            continue;
        }

        if (preg_match('/^## \['.preg_quote($version, '/').'\] - \d{4}-\d{2}-\d{2}$/m', $contents) !== 1) {
            $errors[] = "Release changelog [{$name}] has no dated [{$version}] heading.";
        }

        $unreleased = releaseChangelogSection($contents, 'Unreleased');

        if ($unreleased === null) {
            $errors[] = "Release changelog [{$name}] has no [Unreleased] heading.";
        } elseif (trim($unreleased) !== '') {
            $errors[] = "Release changelog [{$name}] must leave [Unreleased] blank when publishing [{$version}].";
        }

        if (preg_match(
            '/(?:target(?:ed)?|planned|scheduled)\s+(?:for|as)?\s*v?'.preg_quote($version, '/').'\b|target:\s*v?'.preg_quote($version, '/').'\b/i',
            $contents,
        ) === 1) {
            $errors[] = "Release changelog [{$name}] still describes [{$version}] as a future target.";
        }
    }

    return $errors;
}

/** Extract one bracketed second-level changelog section. */
function releaseChangelogSection(string $contents, string $heading): ?string
{
    $matched = preg_match(
        '/^## \['.preg_quote($heading, '/').'\](?: - [^\r\n]+)?\R(?<section>.*?)(?=^## |\z)/ms',
        $contents,
        $matches,
    );

    return $matched === 1 ? $matches['section'] : null;
}

if (isset($_SERVER['SCRIPT_FILENAME'])
    && is_string($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];

    if (! is_array($arguments)) {
        fwrite(STDERR, "Release changelog arguments are unavailable.\n");
        exit(2);
    }

    $version = $arguments[1] ?? null;

    if (! is_string($version)) {
        fwrite(STDERR, "A semantic version is required.\n");
        exit(2);
    }

    $changedPackages = [];

    foreach (array_slice($arguments, 2) as $package) {
        if (! is_string($package) || trim($package) === '') {
            fwrite(STDERR, "Changed package names must be non-empty strings.\n");
            exit(2);
        }

        $changedPackages[] = $package;
    }

    if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/', $version) !== 1) {
        fwrite(STDERR, "A semantic version is required.\n");
        exit(2);
    }

    $errors = releaseChangelogErrors(dirname(__DIR__), $version, $changedPackages);

    if ($errors !== []) {
        fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, "Release changelogs are ready for v{$version}.\n");
}
