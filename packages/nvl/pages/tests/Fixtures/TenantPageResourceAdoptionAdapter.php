<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Creates the constrained dynamic resource fixture before adoption activation. */
final readonly class TenantPageResourceAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.page-resources'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new TenantPageResource)->getTable())) {
            $schema->create((new TenantPageResource)->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('slug');
                $table->string('title');
                $table->boolean('is_public')->default(true);
                $table->timestamps();
                $table->unique(['tenant_id', 'slug'], 'page_tenant_test_resources_slug_unique');
                $table->unique(['tenant_id', 'id'], 'page_tenant_test_resources_tenant_id_unique');
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
        $table = (new TenantPageResource)->getTable();

        return new TenantVerification(
            $schema->hasColumns($table, ['id', 'tenant_id', 'slug'])
                && $schema->hasIndex($table, ['tenant_id', 'slug'], 'unique')
                ? []
                : ['test.page-resources.schema'],
        );
    }

    public function activate(TenantAdoptionPlan $plan): void {}

    private function connection(): Connection
    {
        return (new TenantPageResource)->getConnection();
    }
}
