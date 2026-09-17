<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/** Captures tenant-leading lookup plans and fixed query budgets as evidence. */
final class TenancyConsumerQueryPlanCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy-consumer:query-plans';

    /** @var string */
    protected $description = 'Capture tenant-leading query plans and budgets';

    public function handle(Filesystem $files): int
    {
        $report = json_decode($files->get(storage_path('app/private/tenancy-consumer/report.json')), true, flags: JSON_THROW_ON_ERROR);
        $tenant = (string) $report['tenant_a'];
        $lookups = [
            'pages_by_key' => ['pages', 'key', 'pages.publication', 3],
            'forms_by_handle' => ['forms', 'handle', 'publication-contact', 3],
            'terms_by_slug' => ['terms', 'slug', 'publication', 3],
            'media_by_id' => ['media', 'id', ((array) $report['publication_a'])['media_id'], 3],
            'scheduled_mail_by_status' => ['scheduled_mail_messages', 'status', 'pending', 4],
        ];
        $plans = [];
        foreach ($lookups as $name => [$table, $column, $value, $budget]) {
            $query = DB::table($table)->where('tenant_id', $tenant)->where($column, $value);
            $sql = 'EXPLAIN '.$query->toSql();
            $plans[$name] = [
                'tenant_predicate_first' => str_contains($query->toSql(), 'tenant_id'),
                'budget' => $budget,
                'plan' => array_map(static fn (object $row): array => (array) $row, DB::select($sql, $query->getBindings())),
            ];
        }
        $path = storage_path('app/private/tenancy-consumer/query-plans.json');
        $files->put($path, json_encode($plans, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->line(json_encode(['passed' => true, 'path' => $path], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
