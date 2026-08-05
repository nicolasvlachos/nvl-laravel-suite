<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Data\ContentDefinitionMigrationPlanData;
use Nvl\Content\Data\ContentDefinitionMigrationPlanItemData;
use Nvl\Content\Data\ContentDefinitionMigrationProblemData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionMigrationRegistry;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Builds a bounded read-only migration plan without transforming stored values.
 */
final readonly class PlanContentDefinitionMigrationsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentDefinitionRegistry $definitions,
        private ContentDefinitionMigrationRegistry $migrations,
        private CanonicalJson $json,
    ) {}

    public function execute(
        ContentActorData $actor,
        ?string $definition = null,
        ?int $limit = null,
    ): ContentDefinitionMigrationPlanData {
        $this->authorization->authorize(
            ContentAbility::MigrateDefinitions,
            $actor,
            context: ['definition' => $definition, 'plan' => true],
        );
        $batchLimit = $this->batchLimit($limit);
        $targets = $this->targets($definition);

        if ($targets === []) {
            return new ContentDefinitionMigrationPlanData(
                definition: $definition,
                limit: $batchLimit,
                totalPending: 0,
                hasMore: false,
                ready: [],
                blocked: [],
            );
        }

        $query = $this->pendingQuery($targets);
        $totalPending = (clone $query)->count();
        /** @var Collection<int, ContentBlock> $blocks */
        $blocks = $query
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();
        $ready = [];
        $blocked = [];

        foreach ($blocks as $block) {
            $current = $targets[$block->definition_id] ?? null;

            if (! $current instanceof ContentDefinitionData) {
                continue;
            }

            $this->authorization->authorize(
                ContentAbility::MigrateDefinitions,
                $actor,
                block: $block,
                context: [
                    'definition' => $current->key,
                    'from_version' => $block->definition_version,
                    'to_version' => $current->version,
                    'plan' => true,
                ],
            );

            if (! $this->migrations->hasPath(
                $current->key,
                $block->definition_version,
                $current->version,
            )) {
                $code = $block->definition_version > $current->version
                    ? 'future_version'
                    : 'missing_migration';
                $message = $block->definition_version > $current->version
                    ? "Stored version {$block->definition_version} exceeds current version {$current->version}."
                    : "No complete migration path exists from {$block->definition_version} ".
                        "to {$current->version}.";
                $blocked[] = new ContentDefinitionMigrationProblemData(
                    blockId: $block->id,
                    blockKey: $block->key,
                    definition: $current->key,
                    fromVersion: $block->definition_version,
                    toVersion: $current->version,
                    expectedRevision: $block->revision,
                    deleted: $block->trashed(),
                    code: $code,
                    message: $message,
                );

                continue;
            }

            $versions = [$block->definition_version];

            foreach ($this->migrations->path(
                $current->key,
                $block->definition_version,
                $current->version,
            ) as $migration) {
                $versions[] = $migration->toVersion();
            }

            $ready[] = new ContentDefinitionMigrationPlanItemData(
                blockId: $block->id,
                blockKey: $block->key,
                definition: $current->key,
                fromVersion: $block->definition_version,
                toVersion: $current->version,
                expectedRevision: $block->revision,
                deleted: $block->trashed(),
                versions: $versions,
            );
        }

        return new ContentDefinitionMigrationPlanData(
            definition: $definition,
            limit: $batchLimit,
            totalPending: $totalPending,
            hasMore: $totalPending > $blocks->count(),
            ready: $ready,
            blocked: $blocked,
        );
    }

    private function batchLimit(?int $requested): int
    {
        $default = ContentConfiguration::positiveInteger(
            'content.definition_migration.batch_size',
            100,
        );
        $maximum = ContentConfiguration::positiveInteger(
            'content.definition_migration.maximum_batch_size',
            1_000,
        );
        $limit = $requested ?? $default;

        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException(
                "Content definition migration limit must be between 1 and {$maximum}.",
            );
        }

        return $limit;
    }

    /**
     * @return array<string, ContentDefinitionData>
     */
    private function targets(?string $only): array
    {
        $definitions = $only === null
            ? $this->definitions->all()
            : [$this->definitions->get($only)];
        $mirrors = ContentDefinition::query()
            ->whereIn('key', array_map(
                static fn (ContentDefinitionData $definition): string => $definition->key,
                $definitions,
            ))
            ->get()
            ->keyBy('key');
        $targets = [];

        foreach ($definitions as $definition) {
            $mirror = $mirrors->get($definition->key);

            if (! $mirror instanceof ContentDefinition) {
                throw new InvalidArgumentException(
                    "Content definition [{$definition->key}] is not synchronized.",
                );
            }

            $sourceHash = $this->json->hash($definition->toArray());

            if (! hash_equals($mirror->source_hash, $sourceHash)) {
                throw new InvalidArgumentException(
                    "Content definition [{$definition->key}] is stale; synchronize it before migration.",
                );
            }

            $targets[$mirror->id] = $definition;
        }

        return $targets;
    }

    /**
     * @param  array<string, ContentDefinitionData>  $targets
     * @return Builder<ContentBlock>
     */
    private function pendingQuery(array $targets): Builder
    {
        return ContentBlock::withTrashed()
            ->where(function (Builder $query) use ($targets): void {
                foreach ($targets as $definitionId => $definition) {
                    $query->orWhere(function (Builder $definitionQuery) use (
                        $definition,
                        $definitionId,
                    ): void {
                        $definitionQuery
                            ->where('definition_id', $definitionId)
                            ->where('definition_version', '!=', $definition->version);
                    });
                }
            });
    }
}
