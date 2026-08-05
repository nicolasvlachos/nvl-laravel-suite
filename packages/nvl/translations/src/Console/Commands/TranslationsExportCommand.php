<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;

/**
 * Export translation_entries back to translation files.
 */
final class TranslationsExportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:translations:export
        {--scope=* : Scope tokens (app,module:name,vendor:package,custom:name)}
        {--locales= : Comma-separated locales}
        {--format=both : php|json|both}
        {--target=source : Configured export target name}
        {--prune : Delete stale files for selected scopes, locales, and formats}
        {--dry-run : Report the write plan without changing files or rows}
        {--force : Confirm replacement or pruning without prompting}
        {--output=text : Output format: text or json}';

    /**
     * @var string
     */
    protected $description = 'Export translations from the database back to language files.';

    /**
     * Execute the command.
     */
    public function handle(ExportTranslationsAction $action): int
    {
        /** @var list<string> $scopes */
        $scopes = array_values(array_filter((array) $this->option('scope'), static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));

        $localesOption = trim((string) $this->option('locales'));
        $locales = $localesOption === ''
            ? null
            : array_values(array_filter(array_map('trim', explode(',', $localesOption)), static fn (string $value): bool => $value !== ''));

        $format = trim((string) $this->option('format'));
        if (! in_array($format, ['php', 'json', 'both'], true)) {
            $this->error('Invalid --format option. Allowed values: php, json, both.');

            return self::FAILURE;
        }

        $target = trim((string) $this->option('target'));
        if ($target === '') {
            $this->error('The --target option must name a configured translations.export_targets entry.');

            return self::FAILURE;
        }

        $output = $this->option('output');
        if (! is_string($output) || ! in_array($output, ['text', 'json'], true)) {
            $this->error('Invalid --output option. Allowed values: text, json.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun
            && ! (bool) $this->option('force')
            && ! $this->confirm('Replace selected translation artifacts after creating backups?')) {
            $this->warn('Translation export cancelled.');

            return self::FAILURE;
        }

        $result = $action->execute(
            $scopes,
            $locales,
            $format,
            $target,
            (bool) $this->option('prune'),
            $dryRun,
        );

        if ($output === 'json') {
            $this->line((string) json_encode(
                ['dryRun' => $dryRun, ...$result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $verb = $dryRun ? 'Would resave' : 'Resaved';
            $this->info(sprintf(
                '%s %d files to target [%s] across %d scopes and %d locales; %s %d stale files.',
                $verb,
                $result['files'],
                $result['target'],
                $result['scopes'],
                $result['locales'],
                $dryRun ? 'would prune' : 'pruned',
                $result['deleted'],
            ));
        }

        return self::SUCCESS;
    }
}
