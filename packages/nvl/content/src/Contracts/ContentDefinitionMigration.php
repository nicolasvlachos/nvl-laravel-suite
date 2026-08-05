<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Nvl\Content\Data\ContentDefinitionMigrationContextData;
use Nvl\Content\Data\ContentDefinitionMigrationValuesData;

/**
 * Deterministically upgrades one definition through one sequential version step.
 */
interface ContentDefinitionMigration
{
    public function definitionKey(): string;

    public function fromVersion(): int;

    public function toVersion(): int;

    public function migrate(
        ContentDefinitionMigrationContextData $context,
    ): ContentDefinitionMigrationValuesData;
}
