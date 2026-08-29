<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;

/**
 * Reads one editable block through the package authorization boundary.
 */
final readonly class GetContentBlockAction
{
    public function __construct(private ContentAuthorization $authorization) {}

    public function execute(
        ContentBlock|string $block,
        ContentActorData $actor,
    ): ContentBlockData {
        $blockId = $block instanceof ContentBlock ? $block->id : $block;
        $model = ContentBlock::query()
            ->with(['definition', 'translations'])
            ->findOrFail($blockId);
        $this->authorization->authorize(ContentAbility::View, $actor, $model);

        return ContentBlockData::fromModel($model);
    }
}
