<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Exceptions\ContentDefinitionMigrationRequiredException;
use Nvl\Content\Models\ContentBlock;

/**
 * Prevents schema-aware writes from silently adopting a newer definition.
 */
final class ContentDefinitionVersionGuard
{
    public function assertCurrent(
        ContentBlock $block,
        ContentDefinitionData $definition,
    ): void {
        if ($block->definition_version === $definition->version) {
            return;
        }

        throw ContentDefinitionMigrationRequiredException::forBlock(
            blockId: $block->id,
            definition: $definition->key,
            storedVersion: $block->definition_version,
            currentVersion: $definition->version,
        );
    }
}
