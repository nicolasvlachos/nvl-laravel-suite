<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentCompositionSnapshotBlockData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\ContentSnapshotCopyData;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentCatalogCopyRegistry;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Media\Models\Media;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;

/** Reidentifies a grant-authorized snapshot beneath a canonical tenant owner. */
final readonly class ImportContentSnapshotAction
{
    public function __construct(
        private ContentCatalogCopyRegistry $copies,
        private ContentOwnerRegistry $owners,
        private CanonicalJson $json,
        private TenantContext $context,
        private TenantBoundary $boundary,
    ) {}

    /** @param array<string,string> $mediaMap */
    public function execute(
        Model&ContentOwner $target,
        ContentSnapshotCopyData $source,
        array $mediaMap,
        ContentActorData $actor,
    ): ContentCompositionSnapshotData {
        $tenant = $this->context->requireTenant();
        $this->copies->resolve($source->ownerAlias)->assertAllowed(
            $source->ownerId,
            $source->group,
            $source->grantId,
            $tenant,
        );
        $targetType = $this->owners->type($target);
        $targetId = $this->owners->id($target);
        $this->owners->assertGroup($target, $source->group);
        foreach ($source->mediaIds as $sourceId) {
            $destinationId = $mediaMap[$sourceId]
                ?? throw new TenantBoundaryViolation("Missing copied Media mapping for [{$sourceId}].");
            $this->boundary->query(Media::query(), 'media.assets')->whereKey($destinationId)->firstOrFail();
        }
        $placementIds = [];
        $blockIds = [];
        foreach ($source->snapshot->blocks as $block) {
            $placementIds[$block->placementId] = (string) Str::uuid();
            $blockIds[$block->blockId] = (string) Str::uuid();
        }
        $blocks = array_map(function (ContentCompositionSnapshotBlockData $block) use ($placementIds, $blockIds, $mediaMap): ContentCompositionSnapshotBlockData {
            $data = $this->replaceMedia($block->toArray(), $mediaMap);
            $data['placement_id'] = $placementIds[$block->placementId];
            $data['parent_id'] = $block->parentId === null
                ? null
                : ($placementIds[$block->parentId] ?? throw new TenantBoundaryViolation('Content snapshot parent is missing.'));
            $data['block_id'] = $blockIds[$block->blockId];

            return ContentCompositionSnapshotBlockData::from($data);
        }, $source->snapshot->blocks);
        $payload = [
            'format_version' => 2,
            'tenant_id' => $tenant->value,
            'owner_type' => $targetType,
            'owner_id' => $targetId,
            'group' => $source->group,
            'blocks' => array_map(static fn (ContentCompositionSnapshotBlockData $block): array => $block->toArray(), $blocks),
        ];

        return new ContentCompositionSnapshotData(
            $targetType,
            $targetId,
            $source->group,
            $blocks,
            $this->json->hash($payload),
            $tenant->value,
            2,
        );
    }

    /** @param array<string,string> $mediaMap */
    private function replaceMedia(mixed $value, array $mediaMap): mixed
    {
        if (is_string($value)) {
            return $mediaMap[$value] ?? $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item): mixed => $this->replaceMedia($item, $mediaMap), $value);
    }
}
