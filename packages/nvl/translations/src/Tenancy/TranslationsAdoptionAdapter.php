<?php

declare(strict_types=1);

namespace Nvl\Translations\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionSupport;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

/** Adopts isolated tenant overrides while platform translation catalogs remain fixed. */
final readonly class TranslationsAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(private Migrator $migrator, private TenantAdoptionSupport $adoption) {}

    public function resources(): array
    {
        return ['translations.catalog', 'translations.usages', 'translations.scans', 'translations.overrides'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'translations.overrides');
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $this->adoption->connection($plan, 'translations.overrides');

        return new TenantBackfillResult(null, 0);
    }

    /** @phpstan-impure */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->adoption->connection($plan, 'translations.overrides')->getSchemaBuilder();

        return new TenantVerification($schema->hasColumns(TranslationsTables::TenantOverrides, ['id', 'tenant_id', 'key', 'locale', 'value', 'revision']) ? [] : ['translations.overrides.schema']);
    }

    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Translations tenant override storage did not verify.');
        }
    }
}
