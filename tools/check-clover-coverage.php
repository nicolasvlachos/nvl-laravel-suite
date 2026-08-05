<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php tools/check-clover-coverage.php <clover.xml> <minimum-line> <minimum-branch>\n");

    exit(2);
}

[$script, $reportPath, $minimumLine, $minimumBranch] = $argv;

if (! is_numeric($minimumLine) || ! is_numeric($minimumBranch)) {
    fwrite(STDERR, "Coverage thresholds must be numeric percentages.\n");

    exit(2);
}

$minimumLinePercentage = (float) $minimumLine;
$minimumBranchPercentage = (float) $minimumBranch;

if ($minimumLinePercentage < 0.0
    || $minimumLinePercentage > 100.0
    || $minimumBranchPercentage < 0.0
    || $minimumBranchPercentage > 100.0) {
    fwrite(STDERR, "Coverage thresholds must be between 0 and 100.\n");

    exit(2);
}

if (! is_file($reportPath)) {
    fwrite(STDERR, "Coverage report [{$reportPath}] does not exist.\n");

    exit(2);
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

$metricNodes = $report->xpath('/coverage/project/metrics');
$metrics = is_array($metricNodes) ? ($metricNodes[0] ?? null) : null;

if (! $metrics instanceof SimpleXMLElement) {
    fwrite(STDERR, "Coverage report has no project metrics.\n");

    exit(2);
}

$attributes = $metrics->attributes();

if (! $attributes instanceof SimpleXMLElement) {
    fwrite(STDERR, "Coverage project metrics have no attributes.\n");

    exit(2);
}

$readCounter = static function (string $name) use ($attributes): ?int {
    if (! isset($attributes[$name])) {
        return null;
    }

    $value = (string) $attributes[$name];

    if (preg_match('/\A(?:0|[1-9]\d*)\z/', $value) !== 1) {
        fwrite(STDERR, "Coverage metric [{$name}] must be a canonical non-negative integer.\n");

        exit(2);
    }

    $maximum = (string) PHP_INT_MAX;

    if (strlen($value) > strlen($maximum)
        || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
        fwrite(STDERR, "Coverage metric [{$name}] exceeds the supported integer range.\n");

        exit(2);
    }

    return (int) $value;
};
$statements = $readCounter('statements');
$coveredStatements = $readCounter('coveredstatements');
$branches = $readCounter('conditionals');
$coveredBranches = $readCounter('coveredconditionals');

if ($statements === null || $coveredStatements === null) {
    fwrite(STDERR, "Coverage report is missing statement metrics.\n");

    exit(2);
}

if ($statements === 0) {
    fwrite(STDERR, "Coverage report contains no executable statements.\n");

    exit(2);
}

if ($coveredStatements > $statements) {
    fwrite(STDERR, "Covered statements cannot exceed total statements.\n");

    exit(2);
}

if (($branches === null) !== ($coveredBranches === null)) {
    fwrite(STDERR, "Coverage report contains incomplete branch metrics.\n");

    exit(2);
}

if ($branches !== null && $coveredBranches !== null && $coveredBranches > $branches) {
    fwrite(STDERR, "Covered branches cannot exceed total branches.\n");

    exit(2);
}

$linePercentage = ($coveredStatements / $statements) * 100;
$branchPercentage = $branches === null || $branches === 0
    ? null
    : ($coveredBranches / $branches) * 100;

if ($minimumBranchPercentage > 0.0 && $branchPercentage === null) {
    fwrite(
        STDERR,
        "Branch coverage is required, but the Clover report contains no branch metrics.\n",
    );

    exit(1);
}

if ($branchPercentage === null) {
    printf(
        "Line coverage: %.2f%% (%d/%d); branch coverage: not collected (not required).\n",
        $linePercentage,
        $coveredStatements,
        $statements,
    );
} else {
    printf(
        "Line coverage: %.2f%% (%d/%d); branch coverage: %.2f%% (%d/%d).\n",
        $linePercentage,
        $coveredStatements,
        $statements,
        $branchPercentage,
        $coveredBranches,
        $branches,
    );
}

if ($linePercentage < $minimumLinePercentage
    || ($branchPercentage !== null && $branchPercentage < $minimumBranchPercentage)) {
    fwrite(STDERR, "Coverage thresholds were not met.\n");

    exit(1);
}
