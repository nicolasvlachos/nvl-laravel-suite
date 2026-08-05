<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('enforces coverage for executable PHP lines added after a Git base revision', function (): void {
    $root = dirname(__DIR__, 2);
    $repository = sys_get_temp_dir().'/nvl-changed-coverage-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($repository.'/package/src');
    $sourcePath = $repository.'/package/src/Example.php';
    $initial = <<<'PHP'
<?php

function existingExample(): int
{
    return 1;
}
PHP;
    $changed = $initial.<<<'PHP'


function addedExample(): int
{
    return 2;
}
PHP;

    try {
        $filesystem->dumpFile($sourcePath, $initial);
        changedCoverageGit($repository, ['init', '--quiet']);
        changedCoverageGit($repository, ['config', 'user.email', 'tests@example.test']);
        changedCoverageGit($repository, ['config', 'user.name', 'NVL Tests']);
        changedCoverageGit($repository, ['add', 'package/src/Example.php']);
        changedCoverageGit($repository, ['commit', '--quiet', '-m', 'base']);
        $base = trim(changedCoverageGit($repository, ['rev-parse', 'HEAD']));
        $filesystem->dumpFile($sourcePath, $changed);
        changedCoverageGit($repository, ['add', 'package/src/Example.php']);
        changedCoverageGit($repository, ['commit', '--quiet', '-m', 'change']);

        $coveredReport = changedCoverageReport($sourcePath, 10, 1);
        $uncoveredReport = changedCoverageReport($sourcePath, 10, 0);
        $coveredPath = $repository.'/covered.xml';
        $uncoveredPath = $repository.'/uncovered.xml';
        $filesystem->dumpFile($coveredPath, $coveredReport);
        $filesystem->dumpFile($uncoveredPath, $uncoveredReport);

        $covered = changedCoverageChecker(
            $root,
            $repository,
            $coveredPath,
            $base,
        );
        $uncovered = changedCoverageChecker(
            $root,
            $repository,
            $uncoveredPath,
            $base,
        );

        expect($covered->getExitCode())->toBe(0)
            ->and($covered->getOutput())->toContain('100.00% (1/1)')
            ->and($uncovered->getExitCode())->toBe(1)
            ->and($uncovered->getOutput().$uncovered->getErrorOutput())
            ->toContain('0.00% (0/1)', 'threshold was not met');
    } finally {
        $filesystem->remove($repository);
    }
});

/**
 * Run one deterministic Git command in the isolated coverage fixture.
 *
 * @param  list<string>  $arguments
 */
function changedCoverageGit(string $repository, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $repository);
    $process->setTimeout(10);
    $process->mustRun();

    return $process->getOutput();
}

/**
 * Build one minimal Clover file containing an exact executable line result.
 */
function changedCoverageReport(
    string $sourcePath,
    int $line,
    int $count,
): string {
    return sprintf(
        '<?xml version="1.0"?><coverage><project><file name="%s"><line num="%d" type="stmt" count="%d"/></file></project></coverage>',
        htmlspecialchars($sourcePath, ENT_QUOTES | ENT_XML1),
        $line,
        $count,
    );
}

/**
 * Execute the changed-line policy exactly as CI does.
 */
function changedCoverageChecker(
    string $root,
    string $repository,
    string $report,
    string $base,
): Process {
    $process = new Process([
        PHP_BINARY,
        $root.'/tools/check-changed-clover-coverage.php',
        $report,
        $base,
        'package/src',
        '90',
    ], $repository);
    $process->setTimeout(10);
    $process->run();

    return $process;
}
