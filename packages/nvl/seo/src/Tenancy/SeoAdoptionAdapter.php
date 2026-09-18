<?php

declare(strict_types=1);

namespace Nvl\Seo\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Models\SeoRedirect;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns bounded SEO ownership expansion, derivation, verification, and activation. */
final readonly class SeoAdoptionAdapter implements TenantAdoptionAdapter
{
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionMappings $mappings,
        private SeoOwnerRegistry $owners,
        private TenantResourceRegistry $resources,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['seo.profiles', 'seo.translations', 'seo.redirects'];
    }

    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->connection($plan);
        $this->migrator->usingConnection(
            $plan->connection,
            fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]),
        );
    }

    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->connection($plan);
        $profilePhase = is_string($cursor) && str_starts_with($cursor, 'profiles:');
        $redirects = $profilePhase ? [] : $this->mappings->assignments($plan, 'seo.redirects', $cursor, $limit);

        $connection->transaction(function () use ($connection, $redirects): void {
            foreach ($redirects as $assignment) {
                $row = $connection->table((new SeoRedirect)->getTable())->where('id', $assignment->recordId)->first();
                if ($row === null) {
                    continue;
                }
                if (! is_string($row->scope ?? null) || ! is_string($row->source_path ?? null)) {
                    throw new TenantBoundaryViolation('An SEO redirect has invalid canonical source identity.');
                }
                $connection->table((new SeoRedirect)->getTable())->where('id', $assignment->recordId)->update([
                    'tenant_id' => $assignment->tenantId->value,
                    'source_hash' => SeoRedirect::sourceHashForTenant(
                        $assignment->tenantId->value,
                        $row->scope,
                        is_string($row->locale) ? $row->locale : null,
                        $row->source_path,
                    ),
                ]);
            }
        });

        if ($redirects !== []) {
            $last = $redirects[array_key_last($redirects)];

            return new TenantBackfillResult($last->recordId, count($redirects));
        }

        $profileCursor = $profilePhase ? substr((string) $cursor, strlen('profiles:')) : null;
        $profiles = $connection->table((new SeoProfile)->getTable())
            ->whereNull('tenant_id')
            ->when($profileCursor !== null && $profileCursor !== '', fn ($query) => $query->where('id', '>', $profileCursor))
            ->orderBy('id')->limit($limit)->get();
        $connection->transaction(function () use ($connection, $profiles): void {
            foreach ($profiles as $row) {
                if (! is_string($row->id ?? null) || ! is_string($row->seoable_type) || ! is_string($row->seoable_id)) {
                    throw new TenantBoundaryViolation('An SEO profile has invalid canonical owner identity.');
                }
                $alias = $this->owners->aliasForMorphType($row->seoable_type);
                $class = $this->owners->modelClass($alias);
                $owner = (new $class)->newQueryWithoutScopes()->find($row->seoable_id);
                if (! $owner instanceof Model) {
                    throw new TenantBoundaryViolation('An SEO profile owner cannot be resolved.');
                }
                $this->resources->forModel($owner);
                $tenantId = $owner->getAttribute('tenant_id');
                if (! is_string($tenantId)) {
                    throw new TenantBoundaryViolation('An SEO profile owner has no reviewed tenant identity.');
                }
                $connection->table((new SeoProfile)->getTable())->where('id', $row->id)->update(['tenant_id' => $tenantId]);
                $connection->table((new SeoProfileTranslation)->getTable())->where('seo_profile_id', $row->id)->update(['tenant_id' => $tenantId]);
            }
        });

        if ($profiles->isNotEmpty()) {
            $last = $profiles->last();
            if (! is_string($last->id ?? null)) {
                throw new TenantBoundaryViolation('An SEO profile has no canonical identifier.');
            }

            return new TenantBackfillResult('profiles:'.$last->id, $profiles->count());
        }

        $connection->table(SeoTables::RedirectLocks)->whereNull('tenant_id')->delete();

        return new TenantBackfillResult(null, 0);
    }

    /** @phpstan-impure */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->connection($plan);
        $profiles = (new SeoProfile)->getTable();
        $translations = (new SeoProfileTranslation)->getTable();
        $redirects = (new SeoRedirect)->getTable();
        $errors = [];

        foreach ([$profiles, $translations, $redirects] as $table) {
            if (! $connection->getSchemaBuilder()->hasColumn($table, 'tenant_id')
                || $connection->table($table)->whereNull('tenant_id')->exists()) {
                $errors[] = $table.'.tenant_id';
            }
        }

        if ($connection->table($translations.' as translation')
            ->join($profiles.' as profile', 'profile.id', '=', 'translation.seo_profile_id')
            ->whereColumn('translation.tenant_id', '!=', 'profile.tenant_id')->exists()) {
            $errors[] = 'seo.translations.ownership';
        }

        foreach ($connection->table($profiles)->orderBy('id')->get() as $row) {
            $rowId = is_string($row->id ?? null) ? $row->id : 'unknown';
            try {
                if (! is_string($row->seoable_type) || ! is_string($row->seoable_id)) {
                    throw new TenantBoundaryViolation('Invalid SEO owner identity.');
                }
                $alias = $this->owners->aliasForMorphType($row->seoable_type);
                $class = $this->owners->modelClass($alias);
                $owner = (new $class)->newQueryWithoutScopes()->find($row->seoable_id);
                if (! $owner instanceof Model || $owner->getAttribute('tenant_id') !== $row->tenant_id) {
                    $errors[] = 'seo.profiles.owner_ownership:'.$rowId;
                }
            } catch (\Throwable) {
                $errors[] = 'seo.profiles.owner_ownership:'.$rowId;
            }
            if (count($errors) >= 100) {
                break;
            }
        }

        foreach ($connection->table($redirects)->orderBy('id')->get() as $row) {
            $rowId = is_string($row->id ?? null) ? $row->id : 'unknown';
            if (! is_string($row->tenant_id) || ! is_string($row->scope)
                || ! is_string($row->source_path)
                || ! is_string($row->source_hash)
                || ! hash_equals($row->source_hash, SeoRedirect::sourceHashForTenant(
                    $row->tenant_id,
                    $row->scope,
                    is_string($row->locale) ? $row->locale : null,
                    $row->source_path,
                ))) {
                $errors[] = 'seo.redirects.source_identity:'.$rowId;
            }
            if (count($errors) >= 100) {
                break;
            }
        }

        return new TenantVerification(array_slice(array_unique($errors), 0, 100));
    }

    public function activate(TenantAdoptionPlan $plan): void
    {
        $this->assertVerified($plan, 'SEO tenant ownership did not verify.');
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_170013_constrain_seo_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
        $this->assertVerified($plan, 'SEO tenant ownership failed after constraint activation.');
    }

    /** Require a fresh persisted verification at one activation checkpoint. */
    private function assertVerified(TenantAdoptionPlan $plan, string $message): void
    {
        if ($this->verify($plan)->errors !== []) {
            throw new TenantBoundaryViolation($message);
        }
    }

    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = (new SeoProfile)->setConnection($plan->connection)->getConnection();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('SEO adoption requires its canonical connection.');
        }

        return $connection;
    }
}
