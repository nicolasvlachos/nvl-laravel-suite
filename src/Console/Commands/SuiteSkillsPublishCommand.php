<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Suite\Services\SuiteSkillManager;

/**
 * Publishes every enabled Suite module skill under an ownership manifest.
 *
 * @phpstan-import-type SkillPublicationReport from SuiteSkillManager
 */
final class SuiteSkillsPublishCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:skills:publish
        {--force : Replace locally modified Suite-owned skills; unmanaged skills are never overwritten}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Publish and safely update agent skills for every enabled NVL Suite module';

    /**
     * Publish effective Suite skills and record their ownership and version.
     */
    public function handle(SuiteSkillManager $manager): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->components->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $report = $manager->publish((bool) $this->option('force'));

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf(
            'NVL Suite skills [%s] — manifest: %s',
            $report['suite_version'],
            $report['manifest'],
        ));
        $this->table(
            ['Skill', 'Status', 'Message'],
            array_map(
                static fn (array $result): array => [
                    $result['skill'],
                    $result['status'],
                    $result['message'],
                ],
                $report['results'],
            ),
        );

        if (! $report['healthy']) {
            $this->components->error('One or more Suite skills could not be safely published.');
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
