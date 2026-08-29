<?php

declare(strict_types=1);

use Nvl\Suite\Quality\PackageQualityRunner;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Install root Composer dependencies before running package quality.\n");

    exit(2);
}

require $autoload;
require __DIR__.'/package-quality-runner.php';

$catalog = require __DIR__.'/package-family.php';

if (! is_array($catalog)) {
    fwrite(STDERR, "The package family catalog is invalid.\n");

    exit(2);
}

$validatedCatalog = [];

foreach ($catalog as $key => $value) {
    if (! is_string($key)) {
        fwrite(STDERR, "The package family catalog must use string keys.\n");

        exit(2);
    }

    $validatedCatalog[$key] = $value;
}

/**
 * Execute one root package-quality process and stream its output.
 *
 * @param  list<string>  $command  Process command and arguments.
 * @param  Closure(string, string): void  $stream  Output callback.
 */
function executePackageQualityProcess(
    array $command,
    string $workingDirectory,
    Closure $stream,
): int {
    $process = new Process($command, $workingDirectory, timeout: null);
    $process->run(static function (string $type, string $buffer) use ($stream): void {
        $stream($type, $buffer);
    });

    return $process->getExitCode() ?? 1;
}

$runner = new PackageQualityRunner(
    root: $root,
    catalog: $validatedCatalog,
    execute: executePackageQualityProcess(...),
    writeOutput: static function (string $message): void {
        fwrite(STDOUT, $message);
    },
    writeError: static function (string $message): void {
        fwrite(STDERR, $message);
    },
);

exit($runner->run(array_slice($argv, 1)));
