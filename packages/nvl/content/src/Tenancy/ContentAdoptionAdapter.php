<?php

declare(strict_types=1);

namespace Nvl\Content\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentBlockTranslation;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Models\ContentRevision;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionSupport;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns bounded Content ownership expansion, derivation, verification, and activation. */
final readonly class ContentAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the package adoption boundary. */
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionSupport $adoption,
        private ContentOwnerRegistry $owners,
        private TenantResourceRegistry $resources,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['content.definitions', 'content.blocks', 'content.translations', 'content.revisions', 'content.placements'];
    }

    /** Apply the nullable ownership expansion before any historical mapping. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->adoption->connection($plan, 'content.blocks');
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([dirname(__DIR__, 2).'/database/tenancy-migrations'], ['force' => true]));
    }

    /** Backfill reviewed blocks in bounded order, then derive every child and placement owner. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'content.blocks');
        $placementPhase = is_string($cursor) && str_starts_with($cursor, 'placements:');
        $batch = $placementPhase ? [] : $this->adoption->assignments($plan, 'content.blocks', $cursor, $limit);
        $connection->transaction(function () use ($connection, $batch): void {
            foreach ($batch as $assignment) {
                $ownership = $this->adoption->ownership($assignment, 'content.blocks');
                $connection->table((new ContentBlock)->getTable())->where('id', $assignment->recordId)->update($ownership);
                $connection->table((new ContentBlockTranslation)->getTable())->where('content_block_id', $assignment->recordId)->update(['tenant_id' => $ownership['tenant_id']]);
                $connection->table((new ContentRevision)->getTable())->where('content_block_id', $assignment->recordId)->update(['tenant_id' => $ownership['tenant_id']]);
            }
        });
        if ($batch !== []) {
            return $this->adoption->result($batch);
        }

        $placementCursor = $placementPhase ? substr((string) $cursor, strlen('placements:')) : null;
        $placements = $connection->table((new ContentPlacement)->getTable())
            ->whereNull('tenant_id')
            ->when($placementCursor !== null && $placementCursor !== '', fn ($query) => $query->where('id', '>', $placementCursor))
            ->orderBy('id')->limit($limit)->get();
        $connection->transaction(function () use ($connection, $placements): void {
            foreach ($placements as $row) {
                if (! is_string($row->owner_type) || ! is_string($row->owner_id)) {
                    throw new TenantBoundaryViolation('A Content placement has invalid canonical owner identity.');
                }
                $class = $this->owners->model($row->owner_type);
                $owner = (new $class)->newQueryWithoutScopes()->find($row->owner_id);
                if (! $owner instanceof Model) {
                    throw new TenantBoundaryViolation('A Content placement owner cannot be resolved.');
                }
                $this->resources->forModel($owner);
                $tenantId = $owner->getAttribute('tenant_id');
                if (! is_string($tenantId)) {
                    throw new TenantBoundaryViolation('A Content placement owner has no reviewed tenant identity.');
                }
                $connection->table((new ContentPlacement)->getTable())->where('id', $row->id)->update(['tenant_id' => $tenantId]);
            }
        });

        if ($placements->isNotEmpty()) {
            $last = $placements->last();
            $lastId = $last->id ?? null;
            if (! is_string($lastId) && ! is_int($lastId)) {
                throw new TenantBoundaryViolation('A Content placement has an invalid canonical identity.');
            }

            return new TenantBackfillResult('placements:'.(string) $lastId, $placements->count());
        }

        return new TenantBackfillResult(null, 0);
    }

    /**
     * Verify roots, inherited rows, canonical owners, and cross-graph equality.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'content.blocks');
        $errors = [];
        foreach ([new ContentBlock, new ContentBlockTranslation, new ContentRevision, new ContentPlacement] as $model) {
            if (! $connection->getSchemaBuilder()->hasColumn($model->getTable(), 'tenant_id') || $connection->table($model->getTable())->whereNull('tenant_id')->exists()) {
                $errors[] = $model->getTable().'.tenant_id';
            }
        }
        foreach ([(new ContentBlockTranslation)->getTable(), (new ContentRevision)->getTable()] as $child) {
            if ($connection->table($child.' as child')->join((new ContentBlock)->getTable().' as block', 'block.id', '=', 'child.content_block_id')->whereColumn('child.tenant_id', '!=', 'block.tenant_id')->exists()) {
                $errors[] = $child.'.ownership';
            }
        }
        if ($connection->table((new ContentPlacement)->getTable().' as placement')->join((new ContentBlock)->getTable().' as block', 'block.id', '=', 'placement.content_block_id')->whereColumn('placement.tenant_id', '!=', 'block.tenant_id')->exists()) {
            $errors[] = 'content.placements.block_ownership';
        }
        foreach ($connection->table((new ContentPlacement)->getTable())->orderBy('id')->get() as $row) {
            $rowId = is_string($row->id ?? null) || is_int($row->id ?? null)
                ? (string) $row->id
                : 'unknown';
            if (! is_string($row->owner_type) || ! is_string($row->owner_id)) {
                $errors[] = 'content.placements.owner_identity';

                continue;
            }
            try {
                $class = $this->owners->model($row->owner_type);
                $owner = (new $class)->newQueryWithoutScopes()->find($row->owner_id);
                $resource = $owner instanceof Model ? $this->resources->forModel($owner) : null;
                if (! $owner instanceof Model || $resource === null
                    || $owner->getAttribute('tenant_id') !== $row->tenant_id) {
                    $errors[] = 'content.placements.owner_ownership:'.$rowId;
                }
            } catch (\Throwable) {
                $errors[] = 'content.placements.owner_ownership:'.$rowId;
            }
            if (count($errors) >= 100) {
                break;
            }
        }

        return new TenantVerification(array_slice(array_unique($errors), 0, 100));
    }

    /** Refuse activation until the complete graph has verified. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        $this->assertVerified($plan, 'Content tenant ownership did not verify.');
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_170011_constrain_content_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
        $this->assertVerified($plan, 'Content tenant ownership failed after constraint activation.');
    }

    /** Require a fresh persisted verification at one activation checkpoint. */
    private function assertVerified(TenantAdoptionPlan $plan, string $message): void
    {
        if ($this->verify($plan)->errors !== []) {
            throw new TenantBoundaryViolation($message);
        }
    }
}
