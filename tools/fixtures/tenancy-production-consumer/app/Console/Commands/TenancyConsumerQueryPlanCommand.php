<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Models\Media;
use RuntimeException;

/** Captures tenant-leading lookup plans and fixed query budgets as evidence. */
final class TenancyConsumerQueryPlanCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy-consumer:query-plans';

    /** @var string */
    protected $description = 'Capture tenant-leading query plans and budgets';

    public function handle(Filesystem $files): int
    {
        $decoded = json_decode($files->get(storage_path('app/private/tenancy-consumer/report.json')), true, flags: JSON_THROW_ON_ERROR);
        $report = $this->associativeArray($decoded);
        $publication = $this->associativeArray($report['publication_a'] ?? null);
        $tenant = $this->string($report, 'tenant_a');
        $lookups = [
            'pages_by_key' => ['pages', 'key', 'pages.publication', 3],
            'forms_by_handle' => ['forms', 'handle', 'publication-contact', 3],
            'terms_by_slug' => ['terms', 'slug', 'publication', 3],
            'media_by_id' => [(new Media)->getTable(), 'id', $this->string($publication, 'media_id'), 3],
            'scheduled_mail_by_status' => ['scheduled_mail_messages', 'status', 'pending', 4],
        ];
        $plans = [];
        foreach ($lookups as $name => [$table, $column, $value, $budget]) {
            $query = DB::table($table)->where('tenant_id', $tenant)->where($column, $value);
            $sql = 'EXPLAIN '.$query->toSql();
            $plans[$name] = [
                'tenant_predicate_first' => str_contains($query->toSql(), 'tenant_id'),
                'budget' => $budget,
                'plan' => array_map(
                    static fn (mixed $row): array => is_object($row)
                        ? (array) $row
                        : throw new RuntimeException('The query plan row is invalid.'),
                    DB::select($sql, $query->getBindings()),
                ),
            ];
        }
        $path = storage_path('app/private/tenancy-consumer/query-plans.json');
        $files->put($path, json_encode($plans, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->line(json_encode(['passed' => true, 'path' => $path], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : throw new RuntimeException("Invalid [{$key}] value.");
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('The query plan report is invalid.');
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('The query plan report is invalid.');
            }
            $record[$key] = $item;
        }

        return $record;
    }
}
