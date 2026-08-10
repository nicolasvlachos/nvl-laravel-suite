<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$catalog = require __DIR__.'/package-family.php';
$filesystem = new Filesystem;
$destinationRoot = "{$root}/resources/boost/skills";
$filesystem->remove($destinationRoot);
$filesystem->mkdir($destinationRoot);

foreach ($catalog['packages'] as $package) {
    $source = "{$root}/packages/nvl/{$package}/resources/boost/skills/nvl-{$package}";
    $destination = "{$destinationRoot}/nvl-{$package}";

    if (! is_dir($source)) {
        throw new RuntimeException("Canonical skill directory [{$source}] does not exist.");
    }

    $filesystem->mirror($source, $destination, null, ['override' => true, 'delete' => true]);
}

fwrite(STDOUT, sprintf("Synchronized %d suite Boost skills.\n", count($catalog['packages'])));
