<?php

declare(strict_types=1);

namespace Nvl\Forms\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Query\Builder;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionBoundary;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns reviewed Form-root assignment and canonical child derivation. */
final readonly class FormsAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the Forms adopter. */
    public function __construct(private Migrator $migrator, private TenantAdoptionBoundary $adoption) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['forms.forms', 'forms.entries', 'forms.receipts', 'forms.origins', 'forms.rates', 'forms.analytics', 'forms.translations'];
    }

    /** Install the separately selected ownership schema. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'forms.forms');
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    /** Backfill reviewed roots and derive every child from its canonical Form. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'forms.forms');
        $assignments = $this->adoption->assignments($plan, 'forms.forms', $cursor, $limit);
        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $ownership = $this->adoption->ownership($assignment, 'forms.forms');
                $connection->table(FormsTables::Forms)->where('id', $assignment->recordId)->update($ownership);
                foreach ($this->childTables() as $table) {
                    $connection->table($table)->where('form_id', $assignment->recordId)->update(['tenant_id' => $ownership['tenant_id']]);
                }
            }
        });

        return $this->adoption->result($assignments);
    }

    /**
     * Verify root completeness and exact inherited ownership.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'forms.forms');
        $errors = [];
        if ($connection->table(FormsTables::Forms)->whereNull('tenant_id')->exists()) {
            $errors[] = 'forms.forms.unassigned';
        }
        foreach ($this->childTables() as $table) {
            if ($connection->table($table.' as child')
                ->join(FormsTables::Forms.' as parent', 'parent.id', '=', 'child.form_id')
                ->where(function (Builder $query): void {
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
}
