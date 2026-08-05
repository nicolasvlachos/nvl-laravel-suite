<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;

/**
 * Import translation files into translation_entries.
 */
final class TranslationsImportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:translations:sync
        {--scope=* : Scope tokens (app,module:name,vendor:package,custom:name)}
        {--format=both : php|json|both}
        {--strategy= : fail|prefer-file|prefer-database|interactive}
        {--dry-run : Report the database synchronization plan without writing}
        {--output=text : Output format: text or json}';

    /**
     * @var string
     */
    protected $description = 'Import translations from files into the database.';

    /**
     * Execute the command.
     */
    public function handle(ImportTranslationsAction $action): int
    {
        /** @var list<string> $scopes */
        $scopes = array_values(array_filter((array) $this->option('scope'), static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));

        $formatOption = $this->option('format');
        $format = is_string($formatOption) ? trim($formatOption) : '';
        if (! in_array($format, ['php', 'json', 'both'], true)) {
            $this->error('Invalid --format option. Allowed values: php, json, both.');

            return self::FAILURE;
        }

        $strategyOption = $this->option('strategy');
        $strategy = is_string($strategyOption) ? trim($strategyOption) : '';

        if ($strategy === 'interactive') {
            $choice = $this->choice(
                'How should conflicts be resolved?',
                ['fail', 'prefer-file', 'prefer-database'],
                'fail',
            );
            $strategy = is_string($choice) ? $choice : 'fail';
        }

        if ($strategy !== '') {
            $normalizedStrategy = str_replace('-', '_', $strategy);

            if (! in_array($normalizedStrategy, ['fail', 'prefer_file', 'prefer_database'], true)) {
                $this->error('Invalid conflict strategy.');

                return self::FAILURE;
            }

            config()->set('translations.import.conflict_strategy', $normalizedStrategy);
        }

        $output = $this->option('output');
        if (! is_string($output) || ! in_array($output, ['text', 'json'], true)) {
            $this->error('Invalid --output option. Allowed values: text, json.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $action->execute($scopes, $format, $dryRun);

        if ($output === 'json') {
            $this->line((string) json_encode(
                ['dryRun' => $dryRun, ...$result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->info(sprintf(
                '%s %d entries from %d files across %d scopes (%d created, %d updated, %d database edits preserved, %d conflicts).',
                $dryRun ? 'Would synchronize' : 'Synchronized',
                $result['entries'],
                $result['files'],
                $result['scopes'],
                $result['created'],
                $result['updated'],
                $result['preserved'],
                $result['conflicts'],
            ));
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
