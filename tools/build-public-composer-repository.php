<?php

declare(strict_types=1);

if ($argc < 5 || $argc > 6) {
    fwrite(
        STDERR,
        "Usage: php tools/build-public-composer-repository.php <archive-directory> <version> <dist-base-url> <output-path> [existing-repository]\n",
    );

    exit(2);
}

[, $archiveDirectory, $version, $distBaseUrl, $outputPath] = $argv;
$existingRepositoryPath = $argv[5] ?? null;
$archiveDirectory = rtrim($archiveDirectory, DIRECTORY_SEPARATOR);
$distBaseUrl = rtrim($distBaseUrl, '/');

if (! is_dir($archiveDirectory)) {
    fwrite(STDERR, "Archive directory [{$archiveDirectory}] does not exist.\n");

    exit(2);
}

if (preg_match(
    '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D',
    $version,
) !== 1) {
    fwrite(STDERR, "Version [{$version}] is not a complete semantic version without a v prefix.\n");

    exit(2);
}

$distUrlParts = parse_url($distBaseUrl);

if (($distUrlParts['scheme'] ?? null) !== 'https' || ! is_string($distUrlParts['host'] ?? null)) {
    fwrite(STDERR, "Distribution base URL [{$distBaseUrl}] must be an absolute HTTPS URL.\n");

    exit(2);
}

/** @var array<string, array<string, array<string, mixed>>> $packages */
$packages = [];

if (is_string($existingRepositoryPath)) {
    if (! is_file($existingRepositoryPath)) {
        fwrite(STDERR, "Existing repository [{$existingRepositoryPath}] does not exist.\n");

        exit(2);
    }

    $existingContents = file_get_contents($existingRepositoryPath);

    if (! is_string($existingContents)) {
        throw new RuntimeException(
            "Existing repository [{$existingRepositoryPath}] could not be read.",
        );
    }

    $existingRepository = json_decode(
        $existingContents,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $existingPackages = is_array($existingRepository)
        ? ($existingRepository['packages'] ?? null)
        : null;

    if (! is_array($existingPackages)) {
        throw new RuntimeException(
            "Existing repository [{$existingRepositoryPath}] has no packages map.",
        );
    }

    foreach ($existingPackages as $package => $versions) {
        if (! is_string($package)
            || preg_match('/^nvl\/[a-z0-9-]+$/D', $package) !== 1
            || ! is_array($versions)) {
            throw new RuntimeException(
                "Existing repository [{$existingRepositoryPath}] contains invalid package metadata.",
            );
        }

        foreach ($versions as $existingVersion => $manifest) {
            $existingDist = is_array($manifest) && is_array($manifest['dist'] ?? null)
                ? $manifest['dist']
                : null;
            $existingDistUrl = is_array($existingDist)
                ? ($existingDist['url'] ?? null)
                : null;

            if (! is_string($existingVersion)
                || ! is_array($manifest)
                || ($manifest['name'] ?? null) !== $package
                || ($manifest['version'] ?? null) !== $existingVersion
                || ! is_array($existingDist)
                || ($existingDist['type'] ?? null) !== 'zip'
                || ! is_string($existingDistUrl)
                || ! str_starts_with($existingDistUrl, 'https://')) {
                throw new RuntimeException(
                    "Existing repository metadata for [{$package}:{$existingVersion}] is invalid.",
                );
            }

            $packages[$package][$existingVersion] = $manifest;
        }
    }
}

$archivePaths = glob("{$archiveDirectory}/*.zip") ?: [];
sort($archivePaths);

if ($archivePaths === []) {
    fwrite(STDERR, "Archive directory [{$archiveDirectory}] does not contain ZIP packages.\n");

    exit(2);
}

$indexedPackages = [];

foreach ($archivePaths as $archivePath) {
    $realArchivePath = realpath($archivePath);

    if (! is_string($realArchivePath)) {
        throw new RuntimeException("Archive [{$archivePath}] cannot be resolved.");
    }

    $zip = new ZipArchive;

    if ($zip->open($realArchivePath) !== true) {
        throw new RuntimeException("Archive [{$realArchivePath}] cannot be opened.");
    }

    $manifestContents = $zip->getFromName('composer.json');
    $zip->close();

    if (! is_string($manifestContents)) {
        throw new RuntimeException("Archive [{$realArchivePath}] has no root composer.json.");
    }

    $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
    $package = is_array($manifest) ? ($manifest['name'] ?? null) : null;

    if (! is_string($package) || preg_match('/^nvl\/[a-z0-9-]+$/D', $package) !== 1) {
        throw new RuntimeException(
            "Archive [{$realArchivePath}] does not declare a valid NVL package name.",
        );
    }

    if (($manifest['version'] ?? null) !== $version) {
        throw new RuntimeException(
            "Archive [{$realArchivePath}] must be stamped with version [{$version}] before publication.",
        );
    }

    if (isset($indexedPackages[$package])) {
        throw new RuntimeException(
            "Archive directory contains more than one release artifact for [{$package}].",
        );
    }

    $archiveFilename = basename($realArchivePath);
    $expectedFilename = str_replace('/', '-', $package).'-'.$version.'.zip';

    if ($archiveFilename !== $expectedFilename) {
        throw new RuntimeException(
            "Archive [{$realArchivePath}] must be named [{$expectedFilename}].",
        );
    }

    $sha256 = hash_file('sha256', $realArchivePath);
    $sha1 = hash_file('sha1', $realArchivePath);

    if (! is_string($sha256) || ! is_string($sha1)) {
        throw new RuntimeException("Archive [{$realArchivePath}] could not be hashed.");
    }

    $manifest['dist'] = [
        'type' => 'zip',
        'url' => $distBaseUrl.'/'.rawurlencode($archiveFilename),
        'reference' => $sha256,
        'shasum' => $sha1,
    ];
    $packages[$package][$version] = $manifest;
    $indexedPackages[$package] = true;
}

ksort($packages);

foreach ($packages as &$versions) {
    uksort(
        $versions,
        static fn (string $left, string $right): int => version_compare($right, $left),
    );
}
unset($versions);

$repository = json_encode(
    [
        'packages' => $packages,
        'available-packages' => array_keys($packages),
    ],
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
$outputDirectory = dirname($outputPath);

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException("Output directory [{$outputDirectory}] could not be created.");
}

if (file_put_contents($outputPath, $repository) === false) {
    throw new RuntimeException("Composer repository [{$outputPath}] could not be written.");
}

fwrite(
    STDOUT,
    sprintf(
        "Published metadata for %d package archives at version %s in [%s].\n",
        count($indexedPackages),
        $version,
        $outputPath,
    ),
);
