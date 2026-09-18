<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\ContentSnapshotCopyData;
use Nvl\Content\Services\ContentCatalogCopyRegistry;
use Nvl\Content\Services\ContentMediaReferences;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantContext;
use RuntimeException;

final readonly class ExportContentSnapshotForCopyAction
{
    public function __construct(private ContentCatalogCopyRegistry $copies, private ContentOwnerRegistry $owners, private ContentMediaReferences $media, private TenantContext $context) {}

    public function execute(string $ownerAlias, string $ownerId, string $group, string $grantId): ContentSnapshotCopyData
    {
        $destination = $this->context->requireTenant();
        $this->copies->resolve($ownerAlias)->assertAllowed($ownerId, $group, $grantId, $destination);
        $model = $this->owners->model($ownerAlias);
        $owner = $model::query()->whereKey($ownerId)->firstOrFail();
        $snapshot = $owner->getAttribute('content_snapshot');
        $revision = $owner->getAttribute('revision');
        if (! $snapshot instanceof ContentCompositionSnapshotData || ! is_int($revision)) {
            throw new RuntimeException('Catalog Content owner has no immutable snapshot.');
        }
        $mediaIds = [];
        foreach ($snapshot->blocks as $block) {
            $schema = $block->definitionSchema->toSchema();
            foreach ([$block->values, $block->overrides, ...array_values($block->translations)] as $values) {
                foreach ($this->media->extract($schema, $values, null) as $reference) {
                    $mediaIds[$reference['id']] = true;
                }
            }
        }

        return new ContentSnapshotCopyData($ownerAlias, $ownerId, $group, $grantId, $revision, $snapshot->version, $snapshot, array_keys($mediaIds));
    }
}
