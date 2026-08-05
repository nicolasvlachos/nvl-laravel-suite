<?php

declare(strict_types=1);

namespace Nvl\Content\Exceptions;

use Nvl\Content\Contracts\ContentDefinitionMigration;
use Nvl\Content\Enums\ContentResponseCode;
use Throwable;

/**
 * Safe package failure for one deterministic definition migration step.
 */
final class ContentDefinitionMigrationException extends ContentException
{
    public static function forStep(
        string $blockId,
        string $definition,
        ContentDefinitionMigration $migration,
        Throwable $previous,
    ): self {
        return new self(
            message: "Content block [{$blockId}] failed migration [{$definition}] ".
                "{$migration->fromVersion()}->{$migration->toVersion()}.",
            responseCode: ContentResponseCode::DefinitionMigrationFailed,
            suggestedStatus: 422,
            publicContext: [
                'block_id' => $blockId,
                'definition' => $definition,
                'from_version' => $migration->fromVersion(),
                'to_version' => $migration->toVersion(),
            ],
            previous: $previous,
        );
    }
}
