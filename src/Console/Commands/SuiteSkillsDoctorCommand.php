<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Suite\Services\SuiteSkillManager;

/**
 * Reports agent-skill ownership, version, and content drift without writing.
 *
 * @phpstan-import-type SkillDoctorReport from SuiteSkillManager
 */
final class SuiteSkillsDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:skills:doctor
        {--strict : Treat disabled managed skills as failures}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Check enabled NVL Suite agent skills for ownership, version, and content drift';

    /**
     * Render a read-only Suite skill drift report.
     */
    public function handle(SuiteSkillManager $manager): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->components->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $report = $manager->inspect((bool) $this->option('strict'));

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf(
            'NVL Suite skill doctor [%s] — manifest: %s',
            $report['suite_version'],
            $report['manifest'],
        ));
        $this->table(
            ['Skill', 'Status', 'Severity', 'Message'],
            array_map(
                static fn (array $check): array => [
                    $check['skill'],
                    $check['status'],
                    $check['severity'],
                    $check['message'],
                ],
                $report['checks'],
            ),
        );

        if (! $report['healthy']) {
            $this->components->error('Suite skill drift was detected.');
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
