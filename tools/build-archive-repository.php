<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/build-archive-repository.php <archive-directory> <version>\n");

    exit(2);
}

[, $archiveDirectory, $version] = $argv;
$archiveDirectory = rtrim($archiveDirectory, DIRECTORY_SEPARATOR);

if (! is_dir($archiveDirectory)) {
    fwrite(STDERR, "Archive directory [{$archiveDirectory}] does not exist.\n");

    exit(2);
}

if (preg_match(
    '/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D',
    $version,
) !== 1) {
    fwrite(STDERR, "Version [{$version}] is not a complete semantic version.\n");

    exit(2);
}

$archivePaths = glob("{$archiveDirectory}/*.zip") ?: [];
sort($archivePaths);

if ($archivePaths === []) {
    fwrite(STDERR, "Archive directory [{$archiveDirectory}] does not contain ZIP packages.\n");

    exit(2);
}

/** @var array<string, array<string, array<string, mixed>>> $packages */
$packages = [];

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

    if (! is_array($manifest)) {
        throw new RuntimeException("Archive [{$realArchivePath}] has an invalid Composer manifest.");
    }

    $package = $manifest['name'] ?? null;

    if (! is_string($package) || preg_match('/^nvl\/[a-z0-9-]+$/D', $package) !== 1) {
        throw new RuntimeException(
            "Archive [{$realArchivePath}] does not declare a valid NVL package name.",
        );
    }

    if (isset($packages[$package])) {
        throw new RuntimeException(
            "Archive directory contains more than one release artifact for [{$package}].",
        );
    }

    $manifest['version'] = $version;

    $zip = new ZipArchive;

    if ($zip->open($realArchivePath) !== true) {
        throw new RuntimeException("Archive [{$realArchivePath}] cannot be reopened for version stamping.");
    }

    $stampedManifest = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n";

    if (! $zip->addFromString('composer.json', $stampedManifest)) {
        $zip->close();

        throw new RuntimeException("Archive [{$realArchivePath}] composer.json could not be versioned.");
    }

    if (! $zip->close()) {
        throw new RuntimeException("Archive [{$realArchivePath}] version stamp could not be saved.");
    }

    $manifest['dist'] = [
        'type' => 'zip',
        'url' => 'file://'.$realArchivePath,
        'reference' => hash_file('sha256', $realArchivePath),
        'shasum' => hash_file('sha1', $realArchivePath),
    ];
    $packages[$package] = [$version => $manifest];
}

ksort($packages);
$repository = json_encode(
    ['packages' => $packages],
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";
$repositoryPath = "{$archiveDirectory}/packages.json";

if (file_put_contents($repositoryPath, $repository) === false) {
    throw new RuntimeException("Composer repository [{$repositoryPath}] could not be written.");
}

fwrite(
    STDOUT,
    sprintf(
        "Stamped and indexed %d package archives at version %s in [%s]. Relocated bundles must use a Composer artifact repository.\n",
        count($packages),
        $version,
        $repositoryPath,
    ),
);
