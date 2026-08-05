<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translations\Actions\Sync\ListUnusedTranslationsAction;

/**
 * Report entries that are not used in the latest scan window.
 */
final class TranslationsUnusedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:translations:unused
        {--scope=* : Scope tokens (app,module:name,vendor:package,custom:name)}
        {--days=0 : Use rolling usage window in days instead of latest scan}
        {--limit=200 : Max rows to print}';

    /**
     * @var string
     */
    protected $description = 'List translation entries that appear unused.';

    /**
     * Execute the command.
     */
    public function handle(ListUnusedTranslationsAction $action): int
    {
        /** @var list<string> $scopes */
        $scopes = array_values(array_filter((array) $this->option('scope'), static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        $days = filter_var(
            $this->option('days'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 3650]],
        );
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10_000]],
        );

        if ($days === false) {
            $this->error('The --days option must be an integer between 0 and 3650.');

            return self::FAILURE;
        }

        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 10000.');

            return self::FAILURE;
        }

        $result = $action->execute($scopes, $days);

        $this->info(sprintf('Unused entries: %d', $result['total']));

        if ($result['scanned_at'] !== null) {
            $this->line(sprintf('Reference scan time: %s', $result['scanned_at']->toDateTimeString()));
        }

        if ($result['rows'] === []) {
            return self::SUCCESS;
        }

        $rows = array_slice($result['rows'], 0, $limit);

        $this->table(
            ['Scope', 'Locale', 'Format', 'Key', 'Group'],
            array_map(static fn (array $row): array => [
                $row['scope_type'].':'.$row['scope_name'],
                $row['locale'],
                $row['format'],
                $row['key'],
                $row['group'] ?? '-',
            ], $rows)
        );

        if ($result['total'] > count($rows)) {
            $this->line(sprintf('... %d more rows omitted (increase --limit).', $result['total'] - count($rows)));
        }

        return self::SUCCESS;
    }
}
