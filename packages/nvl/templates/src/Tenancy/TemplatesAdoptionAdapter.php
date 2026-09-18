<?php

declare(strict_types=1);

namespace Nvl\Templates\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionSupport;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Applies reviewed Template ownership and derives every template child partition. */
final readonly class TemplatesAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(private Migrator $migrator, private TenantAdoptionSupport $adoption) {}

    public function resources(): array
    {
        return ['templates.templates', 'templates.translations', 'templates.versions', 'templates.assignments', 'templates.renders'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'templates.templates');
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'templates.templates');
        $assignments = $this->adoption->assignments($plan, 'templates.templates', $cursor, $limit);
        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $ownership = $this->adoption->ownership($assignment, 'templates.templates');
                $template = TemplatesConfiguration::table(TemplatesTables::Templates);
                $connection->table($template)->where('id', $assignment->recordId)->update($ownership);
                foreach ([TemplatesTables::I18n, TemplatesTables::Versions, TemplatesTables::Assignments, TemplatesTables::Renders] as $table) {
                    $updates = ['tenant_id' => $ownership['tenant_id']];
                    if ($table === TemplatesTables::I18n) {
                        if (! isset($ownership['ownership_key'])) {
                            throw new TenantBoundaryViolation('Template adoption requires mixed ownership attributes.');
                        }
                        $updates['ownership_key'] = $ownership['ownership_key'];
                    }
                    $connection->table(TemplatesConfiguration::table($table))->where('template_id', $assignment->recordId)->update($updates);
                }
            }
        });

        return $this->adoption->result($assignments);
    }

    /** @phpstan-impure */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'templates.templates');
        $errors = [];
        $root = TemplatesConfiguration::table(TemplatesTables::Templates);
        foreach ($connection->table($root)->select(['id', 'tenant_id', 'ownership_key'])->cursor() as $row) {
            $expected = is_string($row->tenant_id) ? 'tenant:'.$row->tenant_id : 'platform';
            if ($row->ownership_key !== $expected) {
                $errors[] = 'templates.ownership:'.(is_string($row->id) || is_int($row->id) ? (string) $row->id : 'unknown');
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
}
