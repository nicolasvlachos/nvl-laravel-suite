<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;

/**
 * Removes stale translation artifacts only after an explicit force flag.
 */
final class TranslationsPruneCommand extends Command
{
    protected $signature = 'nvl:translations:prune
        {--scope=* : Scope tokens}
        {--locales= : Comma-separated locales}
        {--format=both : php|json|both}
        {--target=source : Configured export target}
        {--dry-run : Report stale artifacts without deleting}
        {--force : Confirm destructive pruning}';

    protected $description = 'Prune stale translation files with backups and an explicit confirmation';

    public function handle(ExportTranslationsAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! (bool) $this->option('force')) {
            $this->error('Translation pruning requires --force or --dry-run.');

            return self::FAILURE;
        }

        $scopes = array_values(array_filter(
            (array) $this->option('scope'),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $locales = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('locales'))),
            static fn (string $value): bool => $value !== '',
        ));
        $format = (string) $this->option('format');

        if (! in_array($format, ['php', 'json', 'both'], true)) {
            $this->error('Invalid --format option. Allowed values: php, json, both.');

            return self::FAILURE;
        }

        $target = trim((string) $this->option('target'));
        if ($target === '') {
            $this->error('The --target option must name a configured translations.export_targets entry.');

            return self::FAILURE;
        }

        $result = $action->execute(
            $scopes,
            $locales === [] ? null : $locales,
            $format,
            $target,
            true,
            $dryRun,
        );

        $this->info(sprintf(
            '%s %d stale translation files.',
            $dryRun ? 'Would prune' : 'Pruned',
            $result['deleted'],
        ));

        return self::SUCCESS;
    }
}
