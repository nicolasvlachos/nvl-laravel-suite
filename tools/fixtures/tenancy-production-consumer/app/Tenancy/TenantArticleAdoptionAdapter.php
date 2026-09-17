<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\TenantArticle;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns resumable adoption of the fixture's legacy article roots. */
final readonly class TenantArticleAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the adapter around immutable reviewed mappings. */
    public function __construct(private TenantAdoptionMappings $mappings) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['consumer.articles'];
    }

    /** Require the consumer-owned article schema to exist before backfill. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        if (! (new TenantArticle)->getConnection()->getSchemaBuilder()->hasColumns(
            (new TenantArticle)->getTable(),
            ['id', 'tenant_id', 'slug', 'title'],
        )) {
            throw new \RuntimeException('The tenant article fixture schema is unavailable.');
        }
    }

    /** Apply one bounded batch of immutable article assignments. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $assignments = $this->mappings->assignments($plan, 'consumer.articles', $cursor, $limit);
        foreach ($assignments as $assignment) {
            TenantArticle::query()
                ->whereKey($assignment->recordId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $assignment->tenantId->value, 'updated_at' => now()]);
        }

        $nextCursor = count($assignments) === $limit
            ? $assignments[array_key_last($assignments)]->recordId
            : null;

        return new TenantBackfillResult($nextCursor, count($assignments));
    }

    /** Verify every article has a canonical owner matching its reviewed mapping. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $errors = [];
        foreach (TenantArticle::query()->orderBy('id')->get(['id', 'tenant_id']) as $article) {
            if ($article->tenant_id === null
                || $article->tenant_id !== $this->mappings->tenantFor($plan, 'consumer.articles', $article->id)->value) {
                $errors[] = 'consumer.articles:'.$article->id;
            }
        }

        return new TenantVerification($errors);
    }

    /** Keep the fixture constraint strategy unchanged after verified backfill. */
    public function activate(TenantAdoptionPlan $plan): void {}
}
