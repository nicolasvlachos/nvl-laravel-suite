<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionMigrationPlanData;
use Nvl\Content\Data\ContentDefinitionMigrationPlanItemData;
use Nvl\Content\Data\ContentDefinitionMigrationResultData;
use Nvl\Content\Data\ContentDefinitionMigrationResultItemData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\ContentBlockDefinitionMigrator;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Atomically applies one exact revision-safe definition migration plan.
 */
final readonly class ApplyContentDefinitionMigrationsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentBlockDefinitionMigrator $migrator,
    ) {}

    public function execute(
        ContentDefinitionMigrationPlanData $plan,
        ContentActorData $actor,
    ): ContentDefinitionMigrationResultData {
        $this->authorization->authorize(
            ContentAbility::MigrateDefinitions,
            $actor,
            context: [
                'definition' => $plan->definition,
                'count' => count($plan->ready),
                'apply' => true,
            ],
        );

        if ($plan->blocked !== []) {
            throw new InvalidArgumentException(
                'Content definition migration plan contains blocked targets and cannot be applied.',
            );
        }

        $maximum = ContentConfiguration::positiveInteger(
            'content.definition_migration.maximum_batch_size',
            1_000,
        );

        if (count($plan->ready) > $maximum) {
            throw new InvalidArgumentException(
                "Content definition migration plan exceeds the {$maximum} block limit.",
            );
        }

        $blockIds = array_map(
            static fn (ContentDefinitionMigrationPlanItemData $target): string => $target->blockId,
            $plan->ready,
        );

        if (count($blockIds) !== count(array_unique($blockIds))) {
            throw new InvalidArgumentException(
                'Content definition migration plans cannot contain duplicate block targets.',
            );
        }

        if ($plan->ready === []) {
            return new ContentDefinitionMigrationResultData(applied: true, migrated: []);
        }

        $targets = $plan->ready;
        usort(
            $targets,
            static fn (
                ContentDefinitionMigrationPlanItemData $left,
                ContentDefinitionMigrationPlanItemData $right,
            ): int => $left->blockId <=> $right->blockId,
        );
        $attempts = max(
            1,
            ContentConfiguration::positiveInteger(
                'content.definition_migration.transaction_attempts',
                3,
            ),
        );

        return DB::connection((new ContentBlock)->getConnectionName())
            ->transaction(function () use ($actor, $targets): ContentDefinitionMigrationResultData {
                $migrated = [];

                foreach ($targets as $target) {
                    $block = ContentBlock::withTrashed()
                        ->with(['definition', 'translations'])
                        ->lockForUpdate()
                        ->findOrFail($target->blockId);
                    $this->authorization->authorize(
                        ContentAbility::MigrateDefinitions,
                        $actor,
                        block: $block,
                        context: [
                            'definition' => $target->definition,
                            'from_version' => $target->fromVersion,
                            'to_version' => $target->toVersion,
                            'apply' => true,
                        ],
                    );

                    if ($block->revision !== $target->expectedRevision) {
                        throw StaleContentException::forRevision(
                            $block->id,
                            $target->expectedRevision,
                            $block->revision,
                        );
                    }

                    if ($block->definition->key !== $target->definition
                        || $block->definition_version !== $target->fromVersion) {
                        throw new InvalidArgumentException(
                            "Content migration target [{$block->id}] no longer matches its plan.",
                        );
                    }

                    $previousRevision = $block->revision;
                    $updated = $this->migrator->migrate(
                        $block,
                        $target->toVersion,
                        $actor,
                    );
                    $migrated[] = new ContentDefinitionMigrationResultItemData(
                        blockId: $updated->id,
                        definition: $target->definition,
                        fromVersion: $target->fromVersion,
                        toVersion: $updated->definition_version,
                        previousRevision: $previousRevision,
                        revision: $updated->revision,
                    );
                }

                return new ContentDefinitionMigrationResultData(
                    applied: true,
                    migrated: $migrated,
                );
            }, attempts: $attempts);
    }
}
