<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__).'/vendor/autoload.php';

if ($argc !== 5) {
    fwrite(
        STDERR,
        "Usage: php tools/check-changed-clover-coverage.php <clover.xml> <base-ref> <source-root> <minimum-line>\n",
    );

    exit(2);
}

[$script, $reportPath, $baseReference, $sourceRoot, $minimumLine] = $argv;

if (! is_numeric($minimumLine)) {
    fwrite(STDERR, "Changed-line threshold must be a numeric percentage.\n");

    exit(2);
}

$minimumLinePercentage = (float) $minimumLine;

if ($minimumLinePercentage < 0.0 || $minimumLinePercentage > 100.0) {
    fwrite(STDERR, "Changed-line threshold must be between 0 and 100.\n");

    exit(2);
}

$repositoryRoot = getcwd();

if (! is_string($repositoryRoot) || ! is_dir($repositoryRoot.'/.git')) {
    fwrite(STDERR, "Changed-line coverage requires a Git repository working directory.\n");

    exit(2);
}

$sourcePath = realpath($repositoryRoot.'/'.$sourceRoot);

if (! is_string($sourcePath) || ! is_dir($sourcePath)) {
    fwrite(STDERR, "Coverage source root [{$sourceRoot}] does not exist.\n");

    exit(2);
}

if (! is_file($reportPath)) {
    fwrite(STDERR, "Coverage report [{$reportPath}] does not exist.\n");

    exit(2);
}

$diff = new Process([
    'git',
    'diff',
    '--unified=0',
    '--no-color',
    '--diff-filter=ACMR',
    $baseReference.'...HEAD',
    '--',
    $sourceRoot,
], $repositoryRoot);
$diff->setTimeout(30);
$diff->run();

if (! $diff->isSuccessful()) {
    fwrite(STDERR, "Unable to calculate changed source lines.\n");
    fwrite(STDERR, $diff->getErrorOutput());

    exit(2);
}

/** @var array<string, array<int, true>> $changedLines */
$changedLines = [];
$currentFile = null;

foreach (preg_split('/\R/', $diff->getOutput()) ?: [] as $line) {
    if (str_starts_with($line, '+++ b/')) {
        $relativePath = substr($line, 6);
        $absolutePath = realpath($repositoryRoot.'/'.$relativePath);
        $currentFile = is_string($absolutePath)
            && str_ends_with($absolutePath, '.php')
            ? $absolutePath
            : null;

        continue;
    }

    if ($currentFile === null
        || preg_match(
            '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/',
            $line,
            $matches,
        ) !== 1) {
        continue;
    }

    $firstLine = (int) $matches[1];
    $lineCount = isset($matches[2]) ? (int) $matches[2] : 1;

    for ($lineNumber = $firstLine; $lineNumber < $firstLine + $lineCount; $lineNumber++) {
        $changedLines[$currentFile][$lineNumber] = true;
    }
}

if ($changedLines === []) {
    fwrite(STDOUT, "No changed PHP source lines require coverage.\n");

    exit(0);
}

$previousLibxmlState = libxml_use_internal_errors(true);

try {
    $report = simplexml_load_file(
        $reportPath,
        SimpleXMLElement::class,
        LIBXML_NONET | LIBXML_NOBLANKS,
    );
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);
}

if (! $report instanceof SimpleXMLElement) {
    fwrite(STDERR, "Coverage report is not valid XML.\n");

    exit(2);
}

/** @var array<string, array<int, int>> $coverageByFile */
$coverageByFile = [];

foreach ($report->xpath('//file') ?: [] as $file) {
    $reportedPath = (string) $file['name'];
    $absolutePath = realpath(
        str_starts_with($reportedPath, DIRECTORY_SEPARATOR)
            ? $reportedPath
            : $repositoryRoot.'/'.$reportedPath,
    );

    if (! is_string($absolutePath)) {
        continue;
    }

    $coverageByFile[$absolutePath] = [];

    foreach ($file->line as $coveredLine) {
        $lineNumber = filter_var(
            (string) $coveredLine['num'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $executionCount = filter_var(
            (string) $coveredLine['count'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        if (is_int($lineNumber) && is_int($executionCount)) {
            $coverageByFile[$absolutePath][$lineNumber] = $executionCount;
        }
    }
}

$executableChangedLines = 0;
$coveredChangedLines = 0;
/** @var array<string, list<int>> $uncoveredChangedLines */
$uncoveredChangedLines = [];

foreach ($changedLines as $file => $lines) {
    if (! array_key_exists($file, $coverageByFile)) {
        fwrite(STDERR, "Changed source file [{$file}] is absent from the coverage report.\n");

        exit(1);
    }

    foreach (array_keys($lines) as $lineNumber) {
        if (! array_key_exists($lineNumber, $coverageByFile[$file])) {
            continue;
        }

        $executableChangedLines++;

        if ($coverageByFile[$file][$lineNumber] > 0) {
            $coveredChangedLines++;

            continue;
        }

        $uncoveredChangedLines[$file][] = $lineNumber;
    }
}

if ($executableChangedLines === 0) {
    fwrite(STDOUT, "Changed PHP source contains no executable lines reported by Clover.\n");

    exit(0);
}

$percentage = ($coveredChangedLines / $executableChangedLines) * 100;

printf(
    "Changed-line coverage: %.2f%% (%d/%d).\n",
    $percentage,
    $coveredChangedLines,
    $executableChangedLines,
);

if ($percentage < $minimumLinePercentage) {
    fwrite(STDERR, "Changed-line coverage threshold was not met.\n");

    foreach ($uncoveredChangedLines as $file => $lineNumbers) {
        $relativePath = ltrim(
            substr($file, strlen($repositoryRoot)),
            DIRECTORY_SEPARATOR,
        );

        fwrite(
            STDERR,
            sprintf(
                "Uncovered changed lines in [%s]: %s.\n",
                $relativePath,
                implode(', ', $lineNumbers),
            ),
        );
    }

    exit(1);
}
