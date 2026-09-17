<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns the constrained canonical owner schema used by Content tenancy tests. */
final readonly class TenantContentOwnerAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.content-owners'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new TenantContentOwner)->getTable())) {
            $schema->create((new TenantContentOwner)->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->timestamps();
                $table->unique(['tenant_id', 'id'], 'content_tenant_test_owners_tenant_id_unique');
            });
        }
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        return new TenantBackfillResult(null, 0);
    }

    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = (new TenantContentOwner)->getTable();

        return new TenantVerification(
            $schema->hasColumns($table, ['id', 'tenant_id', 'name'])
                && $schema->hasIndex($table, ['tenant_id', 'id'], 'unique')
                ? []
                : ['test.content-owners.schema'],
        );
    }

    public function activate(TenantAdoptionPlan $plan): void {}

    private function connection(): Connection
    {
        return (new TenantContentOwner)->getConnection();
    }
}
