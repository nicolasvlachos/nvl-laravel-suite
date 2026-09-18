<?php

declare(strict_types=1);

namespace Nvl\Pages\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Query\Builder;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Models\PageTranslation;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionBoundary;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns bounded Page-tree expansion, backfill, verification, and activation. */
final readonly class PagesAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the package adoption boundary. */
    public function __construct(private Migrator $migrator, private TenantAdoptionBoundary $adoption) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['pages.pages', 'pages.translations'];
    }

    /** Apply nullable Page ownership and tenant-local key/path indexes. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'pages.pages');
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    /** Backfill reviewed Page roots and derive translation/lock ownership. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'pages.pages');
        $batch = $this->adoption->assignments($plan, 'pages.pages', $cursor, $limit);
        $connection->transaction(function () use ($batch, $connection): void {
            foreach ($batch as $assignment) {
                $ownership = $this->adoption->ownership($assignment, 'pages.pages');
                $connection->table((new Page)->getTable())->where('id', $assignment->recordId)->update($ownership);
                $connection->table((new PageTranslation)->getTable())->where('page_id', $assignment->recordId)->update(['tenant_id' => $ownership['tenant_id']]);
            }
        });
        if ($batch === []) {
            $locks = PagesConfiguration::table(PagesTables::TreeLocks, PagesTables::TreeLocks);
            foreach ($connection->table((new Page)->getTable())
                ->select(['tenant_id', 'site'])->whereNotNull('tenant_id')->distinct()->get() as $row) {
                $connection->table($locks)->insertOrIgnore([
                    'tenant_id' => $row->tenant_id,
                    'site' => $row->site,
                ]);
            }
            $connection->table($locks)->whereNull('tenant_id')->delete();

            return new TenantBackfillResult(null, 0);
        }

        return $this->adoption->result($batch);
    }

    /**
     * Verify Page roots, translations, parents, and site/path identity remain tenant-local.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'pages.pages');
        $pages = (new Page)->getTable();
        $translations = (new PageTranslation)->getTable();
        $errors = [];
        foreach ([$pages, $translations] as $table) {
            if (! $connection->getSchemaBuilder()->hasColumn($table, 'tenant_id') || $connection->table($table)->whereNull('tenant_id')->exists()) {
                $errors[] = $table.'.tenant_id';
            }
        }
        if ($connection->table($translations.' as translation')->join($pages.' as page', 'page.id', '=', 'translation.page_id')->whereColumn('translation.tenant_id', '!=', 'page.tenant_id')->exists()) {
            $errors[] = 'pages.translations.ownership';
        }
        if ($connection->table($pages.' as child')->join($pages.' as parent', 'parent.id', '=', 'child.parent_id')->where(fn (Builder $query): Builder => $query->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->orWhereColumn('child.site', '!=', 'parent.site'))->exists()) {
            $errors[] = 'pages.pages.parent_ownership';
        }

        return new TenantVerification($errors);
    }

    /** Refuse activation until the complete Page tree verifies. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        $this->assertVerified($plan, 'Pages tenant ownership did not verify.');
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_170012_constrain_pages_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
        $this->assertVerified($plan, 'Pages tenant ownership failed after constraint activation.');
    }

    /** Require a fresh persisted verification at one activation checkpoint. */
    private function assertVerified(TenantAdoptionPlan $plan, string $message): void
    {
        if ($this->verify($plan)->errors !== []) {
            throw new TenantBoundaryViolation($message);
        }
    }
}
