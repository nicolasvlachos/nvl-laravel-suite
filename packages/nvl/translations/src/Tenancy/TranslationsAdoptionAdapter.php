<?php

declare(strict_types=1);

namespace Nvl\Translations\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Models\TenantTranslationOverride;

final readonly class TranslationsAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(private Migrator $migrator) {}

    public function resources(): array
    {
        return ['translations.catalog', 'translations.usages', 'translations.scans', 'translations.overrides'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->connection($plan);
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $this->connection($plan);

        return new TenantBackfillResult(null, 0);
    }

    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection($plan)->getSchemaBuilder();

        return new TenantVerification($schema->hasColumns(TranslationsTables::TenantOverrides, ['id', 'tenant_id', 'key', 'locale', 'value', 'revision']) ? [] : ['translations.overrides.schema']);
    }

    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Translations tenant override storage did not verify.');
        }
    }

    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = (new TenantTranslationOverride)->setConnection($plan->connection)->getConnection();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('Translations adoption requires canonical storage.');
        }

        return $connection;
    }
}
