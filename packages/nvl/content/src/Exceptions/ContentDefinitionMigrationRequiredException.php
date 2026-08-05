<?php

declare(strict_types=1);

namespace Nvl\Content\Exceptions;

use Nvl\Content\Enums\ContentResponseCode;

/**
 * Signals that a block must be explicitly migrated before schema-aware mutation.
 */
final class ContentDefinitionMigrationRequiredException extends ContentException
{
    public static function forBlock(
        string $blockId,
        string $definition,
        int $storedVersion,
        int $currentVersion,
    ): self {
        return new self(
            message: "Content block [{$blockId}] uses [{$definition}] version {$storedVersion}; ".
                "migrate it to version {$currentVersion} before editing or publishing.",
            responseCode: ContentResponseCode::DefinitionMigrationRequired,
            suggestedStatus: 409,
            publicContext: [
                'block_id' => $blockId,
                'definition' => $definition,
                'stored_version' => $storedVersion,
                'current_version' => $currentVersion,
            ],
        );
    }
}
