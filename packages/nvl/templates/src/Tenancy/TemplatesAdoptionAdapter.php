<?php

declare(strict_types=1);

namespace Nvl\Templates\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

final readonly class TemplatesAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(private Migrator $migrator, private TenantAdoptionMappings $mappings) {}

    public function resources(): array
    {
        return ['templates.templates', 'templates.translations', 'templates.versions', 'templates.assignments', 'templates.renders'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->connection($plan);
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->connection($plan);
        $assignments = $this->mappings->assignments($plan, 'templates.templates', $cursor, $limit);
        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $template = TemplatesConfiguration::table(TemplatesTables::Templates);
                $connection->table($template)->where('id', $assignment->recordId)->update([
                    'tenant_id' => $assignment->tenantId->value,
                    'ownership_key' => 'tenant:'.$assignment->tenantId->value,
                ]);
                foreach ([TemplatesTables::I18n, TemplatesTables::Versions, TemplatesTables::Assignments, TemplatesTables::Renders] as $table) {
                    $updates = ['tenant_id' => $assignment->tenantId->value];
                    if ($table === TemplatesTables::I18n) {
                        $updates['ownership_key'] = 'tenant:'.$assignment->tenantId->value;
                    }
                    $connection->table(TemplatesConfiguration::table($table))->where('template_id', $assignment->recordId)->update($updates);
                }
            }
        });

        return $assignments === [] ? new TenantBackfillResult(null, 0) : new TenantBackfillResult($assignments[array_key_last($assignments)]->recordId, count($assignments));
    }

    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->connection($plan);
        $errors = [];
        $root = TemplatesConfiguration::table(TemplatesTables::Templates);
        foreach ($connection->table($root)->select(['id', 'tenant_id', 'ownership_key'])->cursor() as $row) {
            $expected = is_string($row->tenant_id) ? 'tenant:'.$row->tenant_id : 'platform';
            if ($row->ownership_key !== $expected) {
                $errors[] = 'templates.ownership:'.$row->id;
            }
        }

        return new TenantVerification($errors);
    }

    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Templates ownership did not verify.');
        }
    }

    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = (new Template)->setConnection($plan->connection)->getConnection();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('Templates adoption requires canonical storage.');
        }

        return $connection;
    }
}
