<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use RuntimeException;

/** Owns the composition consumer's direct tenant owner schema. */
final readonly class TenantResourceCompositionOwnerAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.resource-owners'];
    }

    /** Create the consumer-owned tenant root. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new TenantResourceCompositionOwner)->getTable())) {
            $schema->create((new TenantResourceCompositionOwner)->getTable(), static function (Blueprint $table): void {
                $table->id();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'id'], 'tenant_resource_owner_tenant_id_unique');
            });
        }
    }

    /** The fixture never infers ownership for legacy rows. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        if ($this->connection()->table((new TenantResourceCompositionOwner)->getTable())->exists()) {
            throw new RuntimeException('Composition owner fixture requires reviewed mappings for existing rows.');
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Verify the actual owner partition rather than manufacturing readiness. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = (new TenantResourceCompositionOwner)->getTable();
        $errors = [];
        if (! $schema->hasColumns($table, ['id', 'tenant_id', 'name', 'deleted_at'])) {
            $errors[] = 'test.resource-owners.columns';
        }
        if ($this->connection()->table($table)->whereNull('tenant_id')->exists()) {
            $errors[] = 'test.resource-owners.unmapped';
        }
        if (! $schema->hasIndex($table, ['tenant_id', 'id'], 'unique')) {
            $errors[] = 'test.resource-owners.identity';
        }

        return new TenantVerification($errors);
    }

    /** No additional owner DDL is required. */
    public function activate(TenantAdoptionPlan $plan): void {}

    /** Resolve the exact consumer connection. */
    private function connection(): Connection
    {
        return (new TenantResourceCompositionOwner)->getConnection();
    }
}
