<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Data\ContentDefinitionSyncPlanData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionMigrationRegistry;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentDefinitionSyncLock;

/**
 * Synchronizes the queryable definition mirror without deleting application data.
 */
final readonly class SyncContentDefinitionsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentDefinitionRegistry $definitions,
        private ContentDefinitionMigrationRegistry $migrations,
        private ContentDefinitionSyncLock $syncLock,
        private CanonicalJson $json,
    ) {}

    public function execute(
        ContentActorData $actor,
        bool $dryRun = false,
    ): ContentDefinitionSyncPlanData {
        $this->authorization->authorize(ContentAbility::SyncDefinitions, $actor);
        $registered = [];

        foreach ($this->definitions->all() as $definition) {
            $registered[$definition->key] = [
                'definition' => $definition,
                'hash' => $this->json->hash($definition->toArray()),
            ];
        }

        $existing = ContentDefinition::query()->get()->keyBy('key');
        $plan = $this->plan($registered, $existing, applied: false);

        if ($dryRun) {
            return $plan;
        }

        return $this->syncLock->run(
            fn (): ContentDefinitionSyncPlanData => DB::connection(
                ContentDefinition::query()->getModel()->getConnectionName(),
            )->transaction(function () use ($registered): ContentDefinitionSyncPlanData {
                $existing = ContentDefinition::query()
                    ->orderBy('key')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('key');
                $plan = $this->plan($registered, $existing, applied: true);

                foreach ($registered as $source) {
                    $this->persist($source['definition'], $source['hash']);
                }

                if ($plan->orphan !== []) {
                    ContentDefinition::query()
                        ->whereIn('key', $plan->orphan)
                        ->update([
                            'is_active' => false,
                            'orphaned_at' => now(),
                        ]);
                }

                return $plan;
            }),
        );
    }

    /**
     * @param  array<string, array{definition: ContentDefinitionData, hash: string}>  $registered
     * @param  Collection<string, ContentDefinition>  $existing
     */
    private function plan(
        array $registered,
        Collection $existing,
        bool $applied,
    ): ContentDefinitionSyncPlanData {
        $create = [];
        $update = [];
        $unchanged = [];

        foreach ($registered as $key => $source) {
            $model = $existing->get($key);

            if (! $model instanceof ContentDefinition) {
                $create[] = $key;
            } elseif ($model->source_hash !== $source['hash'] || $model->orphaned_at !== null) {
                $this->assertVersionProgression($model, $source['definition']);
                $update[] = $key;
            } else {
                $unchanged[] = $key;
            }
        }

        $orphan = $existing->keys()
            ->filter(static fn (string $key): bool => ! isset($registered[$key]))
            ->values()
            ->all();
        sort($create);
        sort($update);
        sort($unchanged);
        sort($orphan);

        return new ContentDefinitionSyncPlanData(
            create: $create,
            update: $update,
            unchanged: $unchanged,
            orphan: $orphan,
            applied: $applied,
        );
    }

    private function persist(ContentDefinitionData $definition, string $hash): void
    {
        ContentDefinition::query()->updateOrCreate(
            ['key' => $definition->key],
            [
                'name' => $definition->name,
                'description' => $definition->description,
                'category' => $definition->category,
                'version' => $definition->version,
                'view' => $definition->view,
                'schema' => $definition->schema->toSchema(),
                'defaults' => $definition->defaults === [] ? null : $definition->defaults,
                'allowed_scopes' => $definition->allowedScopes,
                'allowed_regions' => $definition->allowedRegions,
                'is_active' => $definition->isActive,
                'sort_order' => $definition->sortOrder,
                'source_hash' => $hash,
                'synced_at' => now(),
                'orphaned_at' => null,
            ],
        );
    }

    private function assertVersionProgression(
        ContentDefinition $model,
        ContentDefinitionData $definition,
    ): void {
        if ($definition->version < $model->version) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] cannot decrease from version ".
                "{$model->version} to {$definition->version}.",
            );
        }

        if ($definition->version > $model->version) {
            $versions = ContentBlock::withTrashed()
                ->where('definition_id', $model->id)
                ->distinct()
                ->pluck('definition_version');

            foreach ($versions as $version) {
                if (! is_int($version) && ! is_string($version)) {
                    throw new InvalidArgumentException(
                        "Content definition [{$definition->key}] has an invalid stored block version.",
                    );
                }

                $storedVersion = (int) $version;

                if (! $this->migrations->hasPath(
                    $definition->key,
                    $storedVersion,
                    $definition->version,
                )) {
                    throw new InvalidArgumentException(
                        "Content definition [{$definition->key}] cannot synchronize to version ".
                        "{$definition->version}; stored blocks at version {$storedVersion} ".
                        'do not have a complete migration path.',
                    );
                }
            }
        }

        $storedContract = [
            'schema' => $model->schema->toArray(),
            'defaults' => $model->defaults ?? [],
            'allowed_scopes' => $model->allowed_scopes ?? [],
            'allowed_regions' => $model->allowed_regions ?? [],
            'view' => $model->view,
        ];
        $sourceContract = [
            'schema' => $definition->schema->toSchema()->toArray(),
            'defaults' => $definition->defaults,
            'allowed_scopes' => $definition->allowedScopes,
            'allowed_regions' => $definition->allowedRegions,
            'view' => $definition->view,
        ];

        if ($this->json->hash($storedContract) !== $this->json->hash($sourceContract)
            && $definition->version <= $model->version) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] must increase its version when its contract changes.",
            );
        }
    }
}
