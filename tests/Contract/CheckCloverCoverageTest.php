<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @param  array<string, int|string>|string  $metrics
 */
it('enforces line and optional branch coverage without inventing absent metrics', function (
    array|string $metrics,
    float|string $minimumLine,
    float|string $minimumBranch,
    int $expectedExitCode,
    string $expectedMessage,
): void {
    $reportPath = tempnam(sys_get_temp_dir(), 'nvl-clover-');

    if (! is_string($reportPath)) {
        throw new RuntimeException('Unable to create a temporary Clover report.');
    }

    if (is_string($metrics)) {
        $report = $metrics;
    } else {
        $attributes = [];

        foreach ($metrics as $name => $value) {
            $attributes[] = sprintf(
                '%s="%s"',
                $name,
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1),
            );
        }

        $report = sprintf(
            '<?xml version="1.0"?><coverage><project><metrics %s/></project></coverage>',
            implode(' ', $attributes),
        );
    }

    try {
        if (file_put_contents($reportPath, $report) === false) {
            throw new RuntimeException('Unable to write the temporary Clover report.');
        }

        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/tools/check-clover-coverage.php',
            $reportPath,
            (string) $minimumLine,
            (string) $minimumBranch,
        ]);
        $process->setTimeout(5);
        $process->run();

        expect($process->getExitCode())->toBe($expectedExitCode)
            ->and($process->getOutput().$process->getErrorOutput())
            ->toContain($expectedMessage);
    } finally {
        if (is_file($reportPath)) {
            unlink($reportPath);
        }
    }
})->with([
    'line-only report accepted when branches are not required' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
            'conditionals' => 0,
            'coveredconditionals' => 0,
        ],
        90.0,
        0.0,
        0,
        'branch coverage: not collected (not required)',
    ],
    'missing branch metrics rejected when branches are required' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
            'conditionals' => 0,
            'coveredconditionals' => 0,
        ],
        90.0,
        80.0,
        1,
        'Branch coverage is required',
    ],
    'line and branch thresholds accepted when both are met' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
            'conditionals' => 10,
            'coveredconditionals' => 8,
        ],
        90.0,
        80.0,
        0,
        'branch coverage: 80.00% (8/10)',
    ],
    'reported branches remain subject to their threshold' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
            'conditionals' => 10,
            'coveredconditionals' => 7,
        ],
        90.0,
        80.0,
        1,
        'Coverage thresholds were not met',
    ],
    'line coverage remains subject to its threshold' => [
        [
            'statements' => 10,
            'coveredstatements' => 8,
            'conditionals' => 0,
            'coveredconditionals' => 0,
        ],
        90.0,
        0.0,
        1,
        'Coverage thresholds were not met',
    ],
    'missing statement metrics are rejected' => [
        [
            'conditionals' => 0,
            'coveredconditionals' => 0,
        ],
        90.0,
        0.0,
        2,
        'missing statement metrics',
    ],
    'an empty source report is rejected' => [
        [
            'statements' => 0,
            'coveredstatements' => 0,
            'conditionals' => 0,
            'coveredconditionals' => 0,
        ],
        90.0,
        0.0,
        2,
        'no executable statements',
    ],
    'inconsistent statement counters are rejected' => [
        [
            'statements' => 10,
            'coveredstatements' => 11,
            'conditionals' => 0,
            'coveredconditionals' => 0,
        ],
        90.0,
        0.0,
        2,
        'Covered statements cannot exceed',
    ],
    'incomplete branch counters are rejected' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
            'conditionals' => 5,
        ],
        90.0,
        0.0,
        2,
        'incomplete branch metrics',
    ],
    'inconsistent branch counters are rejected' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
            'conditionals' => 5,
            'coveredconditionals' => 6,
        ],
        90.0,
        0.0,
        2,
        'Covered branches cannot exceed',
    ],
    'negative counters are rejected' => [
        [
            'statements' => '-1',
            'coveredstatements' => 0,
        ],
        90.0,
        0.0,
        2,
        'canonical non-negative integer',
    ],
    'fractional counters are rejected' => [
        [
            'statements' => '10.5',
            'coveredstatements' => 9,
        ],
        90.0,
        0.0,
        2,
        'canonical non-negative integer',
    ],
    'overflowing counters are rejected' => [
        [
            'statements' => '999999999999999999999999999999999999',
            'coveredstatements' => 9,
        ],
        90.0,
        0.0,
        2,
        'supported integer range',
    ],
    'malformed XML is rejected without parser warnings' => [
        '<coverage><project><metrics>',
        90.0,
        0.0,
        2,
        'not valid XML',
    ],
    'a report without project metrics is rejected' => [
        '<coverage/>',
        90.0,
        0.0,
        2,
        'no project metrics',
    ],
    'out-of-range thresholds are rejected' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
        ],
        101.0,
        0.0,
        2,
        'between 0 and 100',
    ],
    'non-numeric thresholds are rejected' => [
        [
            'statements' => 10,
            'coveredstatements' => 9,
        ],
        'ninety',
        0.0,
        2,
        'must be numeric',
    ],
]);
