<?php

declare(strict_types=1);

namespace Nvl\Forms\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Models\Form;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns reviewed Form-root assignment and canonical child derivation. */
final readonly class FormsAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the Forms adopter. */
    public function __construct(private Migrator $migrator, private TenantAdoptionMappings $mappings) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['forms.forms', 'forms.entries', 'forms.receipts', 'forms.origins', 'forms.rates', 'forms.analytics', 'forms.translations'];
    }

    /** Install the separately selected ownership schema. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->connection($plan);
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    /** Backfill reviewed roots and derive every child from its canonical Form. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->connection($plan);
        $assignments = $this->mappings->assignments($plan, 'forms.forms', $cursor, $limit);
        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $connection->table(FormsTables::Forms)->where('id', $assignment->recordId)->update(['tenant_id' => $assignment->tenantId->value]);
                foreach ($this->childTables() as $table) {
                    $connection->table($table)->where('form_id', $assignment->recordId)->update(['tenant_id' => $assignment->tenantId->value]);
                }
            }
        });

        return $assignments === []
            ? new TenantBackfillResult(null, 0)
            : new TenantBackfillResult($assignments[array_key_last($assignments)]->recordId, count($assignments));
    }

    /** Verify root completeness and exact inherited ownership. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->connection($plan);
        $errors = [];
        if ($connection->table(FormsTables::Forms)->whereNull('tenant_id')->exists()) {
            $errors[] = 'forms.forms.unassigned';
        }
        foreach ($this->childTables() as $table) {
            if ($connection->table($table.' as child')
                ->join(FormsTables::Forms.' as parent', 'parent.id', '=', 'child.form_id')
                ->where(function ($query): void {
                    $query->whereNull('child.tenant_id')->orWhereColumn('child.tenant_id', '!=', 'parent.tenant_id');
                })->exists()) {
                $errors[] = $table.'.ownership';
            }
        }

        return new TenantVerification($errors);
    }

    /** Require verified ownership before activation. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Forms tenant ownership did not verify.');
        }
    }

    /** @return list<string> */
    private function childTables(): array
    {
        return [FormsTables::Entries, FormsTables::SubmissionReceipts, FormsTables::AllowedOrigins, FormsTables::Analytics, FormsTables::RateLimits, FormsTables::I18n];
    }

    /** Resolve canonical Forms storage. */
    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = (new Form)->setConnection($plan->connection)->getConnection();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('Forms adoption requires its canonical connection.');
        }

        return $connection;
    }
}
