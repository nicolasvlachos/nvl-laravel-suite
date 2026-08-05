<?php

declare(strict_types=1);

namespace Nvl\Content\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionMigrationRegistry;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentReferenceRegistry;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Support\ContentRouteConfiguration;
use Nvl\Media\Models\MediaAssociation;
use Throwable;

/**
 * Performs non-mutating installation and configuration diagnostics.
 */
final class ContentDoctorCommand extends Command
{
    /**
     * @var array<string, array{
     *     types: array<string, list<string>>,
     *     nullable: list<string>,
     *     defaults: array<string, bool|int|string>
     * }>
     */
    private const COLUMN_REQUIREMENTS = [
        'definitions' => [
            'types' => [
                'uuid' => ['id'],
                'string' => ['key', 'name', 'category', 'view', 'source_hash'],
                'text' => ['description'],
                'json' => ['schema', 'defaults', 'allowed_scopes', 'allowed_regions'],
                'boolean' => ['is_active'],
                'integer' => ['version', 'sort_order'],
                'timestamp' => ['synced_at', 'orphaned_at', 'created_at', 'updated_at'],
            ],
            'nullable' => [
                'description', 'view', 'defaults', 'allowed_scopes', 'allowed_regions',
                'synced_at', 'orphaned_at', 'created_at', 'updated_at',
            ],
            'defaults' => [
                'category' => 'content',
                'version' => 1,
                'is_active' => true,
                'sort_order' => 0,
            ],
        ],
        'blocks' => [
            'types' => [
                'uuid' => ['id', 'definition_id'],
                'string' => [
                    'key', 'scope', 'scope_key', 'status', 'visibility',
                    'definition_hash', 'definition_view',
                    'published_by_type', 'published_by_id',
                    'created_by_type', 'created_by_id',
                    'updated_by_type', 'updated_by_id',
                ],
                'json' => ['values', 'metadata', 'definition_schema'],
                'integer' => ['definition_version', 'revision'],
                'timestamp' => ['published_at', 'created_at', 'updated_at', 'deleted_at'],
            ],
            'nullable' => [
                'values', 'metadata', 'definition_view',
                'published_by_type', 'published_by_id', 'published_at',
                'created_by_type', 'created_by_id',
                'updated_by_type', 'updated_by_id',
                'created_at', 'updated_at', 'deleted_at',
            ],
            'defaults' => [
                'scope' => 'global',
                'scope_key' => '*',
                'status' => 'draft',
                'visibility' => 'public',
                'revision' => 1,
            ],
        ],
        'blocks_i18n' => [
            'types' => [
                'uuid' => ['id', 'content_block_id'],
                'string' => ['locale'],
                'json' => ['values'],
                'timestamp' => ['created_at', 'updated_at'],
            ],
            'nullable' => ['created_at', 'updated_at'],
            'defaults' => [],
        ],
        'placements' => [
            'types' => [
                'uuid' => ['id', 'content_block_id', 'parent_id'],
                'string' => ['owner_type', 'owner_id', 'group', 'key', 'region'],
                'json' => ['overrides'],
                'boolean' => ['is_visible'],
                'integer' => ['sort_order', 'revision'],
                'timestamp' => ['created_at', 'updated_at'],
            ],
            'nullable' => ['parent_id', 'overrides', 'created_at', 'updated_at'],
            'defaults' => [
                'group' => 'default',
                'region' => 'main',
                'sort_order' => 0,
                'is_visible' => true,
                'revision' => 1,
            ],
        ],
        'revisions' => [
            'types' => [
                'uuid' => ['id', 'content_block_id'],
                'string' => ['event', 'actor_type', 'actor_id'],
                'json' => ['snapshot'],
                'integer' => ['revision'],
                'timestamp' => ['created_at', 'updated_at'],
            ],
            'nullable' => ['actor_type', 'actor_id', 'created_at', 'updated_at'],
            'defaults' => [],
        ],
    ];

    /** @var string */
    protected $signature = 'nvl:content:doctor
        {--strict : Return a non-zero status when any required check fails}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Inspect the NVL Content installation without changing state';

    public function handle(
        ContentDefinitionRegistry $definitions,
        ContentDefinitionMigrationRegistry $definitionMigrations,
        ContentFieldPresetRegistry $presets,
        ContentFieldTypeRegistry $fieldTypes,
        ContentOwnerRegistry $owners,
        ContentReferenceRegistry $references,
        Container $container,
        DatabaseManager $database,
        Factory $views,
        CanonicalJson $json,
        Repository $cache,
    ): int {
        $format = $this->option('format');
        $cacheSupportsLocks = $cache->getStore() instanceof LockProvider;

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException(
                'The content doctor format must be text or json.',
            );
        }

        $definitionList = $definitions->all();
        $checks = [
            'binding.authorization' => $container->bound(ContentAuthorization::class),
            'definitions' => array_map(
                static fn (ContentDefinitionData $definition): string => $definition->key,
                $definitionList,
            ),
            'definition_migrations' => $definitionMigrations->identifiers(),
            'presets' => $presets->aliases(),
            'field_types' => $fieldTypes->aliases(),
            'owners' => $owners->aliases(),
            'references' => $references->aliases(),
            'management_routes' => (bool) config('content.routes.management.enabled', false),
            'public_routes' => (bool) config('content.routes.public.enabled', false),
            'view.default' => $this->defaultViewExists($views),
            'cache.placement_locks' => $cacheSupportsLocks,
            'cache.definition_sync_locks' => $cacheSupportsLocks,
        ];

        try {
            ContentRouteConfiguration::path('management');
            ContentRouteConfiguration::name('management');
            ContentRouteConfiguration::middleware('management');
            ContentRouteConfiguration::path('public');
            ContentRouteConfiguration::name('public');
            ContentRouteConfiguration::middleware('public');
            ContentConfiguration::positiveInteger('content.placements.lock_seconds', 30);
            ContentConfiguration::positiveInteger('content.placements.lock_wait_seconds', 10);
            ContentConfiguration::positiveInteger('content.definition_sync.lock_seconds', 60);
            ContentConfiguration::positiveInteger('content.definition_sync.lock_wait_seconds', 10);
            $checks['routes.configuration'] = true;
        } catch (Throwable $exception) {
            $checks['routes.configuration'] = false;
            $checks['routes.error'] = $exception->getMessage();
        }

        try {
            $schema = Schema::connection(ContentConfiguration::connection());

            foreach (['definitions', 'blocks', 'blocks_i18n', 'placements', 'revisions'] as $key) {
                $checks["table.{$key}"] = $schema->hasTable(ContentConfiguration::table($key));
            }

            $checks['schema.columns'] = $this->requiredColumnsExist($schema);
            $checks['schema.indexes'] = $this->requiredIndexesExist($schema);
            $checks['schema.foreign_keys'] = $this->requiredForeignKeysExist($schema);
            $checks['definitions.synchronized'] = $this->definitionsSynchronized(
                $definitionList,
                $json,
            );
            $migrationHealth = $this->definitionMigrationHealth(
                $definitionList,
                $definitionMigrations,
            );
            $checks['definitions.block_versions_current'] = $migrationHealth['current'];
            $checks['definitions.migration_paths'] = $migrationHealth['paths'];
            $checks['definitions.pending_migrations'] = $migrationHealth['pending'];
            $checks['placements.declared_groups'] = $this->placementsUseDeclaredGroups(
                $owners,
            );
        } catch (Throwable $exception) {
            $checks['database.error'] = $exception->getMessage();

            foreach (['definitions', 'blocks', 'blocks_i18n', 'placements', 'revisions'] as $key) {
                $checks["table.{$key}"] = false;
            }

            $checks['schema.columns'] = false;
            $checks['schema.indexes'] = false;
            $checks['schema.foreign_keys'] = false;
            $checks['definitions.synchronized'] = false;
            $checks['definitions.block_versions_current'] = false;
            $checks['definitions.migration_paths'] = false;
            $checks['definitions.pending_migrations'] = null;
            $checks['placements.declared_groups'] = false;
        }

        try {
            $checks['media.atomic_connection'] = $database
                ->connection(ContentConfiguration::connection())
                ->getName() === $database
                ->connection((new MediaAssociation)->getConnectionName())
                ->getName();
        } catch (Throwable $exception) {
            $checks['media.atomic_connection'] = false;
            $checks['media.connection_error'] = $exception->getMessage();
        }

        $healthChecks = [
            'binding.authorization',
            'view.default',
            'routes.configuration',
            'table.definitions',
            'table.blocks',
            'table.blocks_i18n',
            'table.placements',
            'table.revisions',
            'schema.columns',
            'schema.indexes',
            'schema.foreign_keys',
            'definitions.synchronized',
            'definitions.block_versions_current',
            'definitions.migration_paths',
            'placements.declared_groups',
            'media.atomic_connection',
            'cache.placement_locks',
            'cache.definition_sync_locks',
        ];
        $healthy = collect($healthChecks)
            ->every(static fn (string $key): bool => ($checks[$key] ?? false) === true);
        $checks['healthy'] = $healthy;

        if ($format === 'json') {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check => $value) {
                $this->line(sprintf(
                    '%-34s %s',
                    $check,
                    json_encode($value, JSON_THROW_ON_ERROR),
                ));
            }
        }

        return $healthy || ! $this->option('strict') ? self::SUCCESS : self::FAILURE;
    }

    private function defaultViewExists(Factory $views): bool
    {
        $view = config('content.rendering.default_view', 'nvl-content::blocks.default');

        return is_string($view) && $view !== '' && $views->exists($view);
    }

    private function requiredColumnsExist(Builder $schema): bool
    {
        foreach (self::COLUMN_REQUIREMENTS as $tableKey => $requirements) {
            $table = ContentConfiguration::table($tableKey);

            if (! $schema->hasTable($table)) {
                return false;
            }

            $columnsByName = [];

            foreach ($schema->getColumns($table) as $column) {
                $name = $column['name'];
                $columnsByName[$name] = $column;
            }

            foreach ($requirements['types'] as $type => $columnNames) {
                foreach ($columnNames as $columnName) {
                    $column = $columnsByName[$columnName] ?? null;

                    if ($column === null
                        || ! $this->columnTypeMatches($column, $type)
                        || $column['nullable'] !== in_array(
                            $columnName,
                            $requirements['nullable'],
                            true,
                        )
                        || ! $this->columnDefaultMatches(
                            $column['default'],
                            $requirements['defaults'][$columnName] ?? null,
                        )) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Determine whether an introspected column belongs to the required portable type family.
     *
     * @param  array{
     *     name: string,
     *     type: string,
     *     type_name: string,
     *     collation: string|null,
     *     nullable: bool,
     *     default: mixed,
     *     auto_increment: bool,
     *     comment: string|null,
     *     generation: array{type: string|null, expression: string|null}|null
     * }  $column
     */
    private function columnTypeMatches(array $column, string $expectedType): bool
    {
        $type = strtolower($column['type_name']);

        $allowedTypes = match ($expectedType) {
            'uuid' => ['uuid', 'varchar', 'char', 'bpchar'],
            'string' => ['varchar', 'char', 'bpchar'],
            'text' => ['text'],
            'json' => ['json', 'jsonb', 'text'],
            'boolean' => ['boolean', 'bool', 'tinyint'],
            'integer' => ['integer', 'int', 'int4', 'int8', 'bigint'],
            'timestamp' => ['datetime', 'timestamp', 'timestamptz'],
            default => [],
        };

        return in_array($type, $allowedTypes, true);
    }

    /**
     * Determine whether a driver-specific default matches the required literal.
     */
    private function columnDefaultMatches(
        mixed $actual,
        bool|int|string|null $expected,
    ): bool {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        $normalized = $this->normalizeColumnDefault($actual);

        if ($normalized === null) {
            return false;
        }

        if (is_bool($expected)) {
            return in_array(
                strtolower($normalized),
                $expected ? ['1', 'true', 't'] : ['0', 'false', 'f'],
                true,
            );
        }

        if (is_int($expected)) {
            return preg_match('/^-?\d+$/D', $normalized) === 1
                && (int) $normalized === $expected;
        }

        return $normalized === $expected;
    }

    /**
     * Normalize scalar default literals returned by supported database drivers.
     */
    private function normalizeColumnDefault(mixed $default): ?string
    {
        if (is_bool($default)) {
            return $default ? 'true' : 'false';
        }

        if (is_int($default) || is_float($default)) {
            return (string) $default;
        }

        if (! is_string($default)) {
            return null;
        }

        $normalized = trim($default);

        while (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $normalized = trim(substr($normalized, 1, -1));
        }

        $castPosition = strpos($normalized, '::');

        if ($castPosition !== false) {
            $normalized = trim(substr($normalized, 0, $castPosition));
        }

        while (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $normalized = trim(substr($normalized, 1, -1));
        }

        if (strlen($normalized) >= 2
            && $normalized[0] === "'"
            && $normalized[strlen($normalized) - 1] === "'") {
            $normalized = str_replace("''", "'", substr($normalized, 1, -1));
        }

        return $normalized;
    }

    private function requiredIndexesExist(Builder $schema): bool
    {
        /** @var array<string, array<string, array{columns: list<string>, unique: bool}>> $requirements */
        $requirements = [
            'definitions' => [
                'content_definitions_browse_idx' => [
                    'columns' => ['is_active', 'category', 'sort_order'],
                    'unique' => false,
                ],
                'content_definitions_sync_idx' => [
                    'columns' => ['source_hash', 'orphaned_at'],
                    'unique' => false,
                ],
            ],
            'blocks' => [
                'content_blocks_scope_key_unique' => [
                    'columns' => ['scope', 'scope_key', 'key'],
                    'unique' => true,
                ],
                'content_blocks_definition_state_idx' => [
                    'columns' => ['definition_id', 'status', 'visibility'],
                    'unique' => false,
                ],
                'content_blocks_definition_version_idx' => [
                    'columns' => ['definition_id', 'definition_version', 'id'],
                    'unique' => false,
                ],
                'content_blocks_scope_state_idx' => [
                    'columns' => ['scope', 'scope_key', 'status'],
                    'unique' => false,
                ],
                'content_blocks_publication_idx' => [
                    'columns' => ['status', 'published_at'],
                    'unique' => false,
                ],
                'content_blocks_published_by_idx' => [
                    'columns' => ['published_by_type', 'published_by_id'],
                    'unique' => false,
                ],
                'content_blocks_created_by_idx' => [
                    'columns' => ['created_by_type', 'created_by_id'],
                    'unique' => false,
                ],
                'content_blocks_updated_by_idx' => [
                    'columns' => ['updated_by_type', 'updated_by_id'],
                    'unique' => false,
                ],
            ],
            'blocks_i18n' => [
                'content_blocks_i18n_locale_unique' => [
                    'columns' => ['content_block_id', 'locale'],
                    'unique' => true,
                ],
                'content_blocks_i18n_lookup_idx' => [
                    'columns' => ['locale', 'content_block_id'],
                    'unique' => false,
                ],
            ],
            'placements' => [
                'content_placements_owner_group_key_unique' => [
                    'columns' => ['owner_type', 'owner_id', 'group', 'key'],
                    'unique' => true,
                ],
                'content_placements_group_composition_idx' => [
                    'columns' => [
                        'owner_type',
                        'owner_id',
                        'group',
                        'region',
                        'sort_order',
                        'id',
                    ],
                    'unique' => false,
                ],
                'content_placements_parent_idx' => [
                    'columns' => ['parent_id', 'sort_order', 'id'],
                    'unique' => false,
                ],
                'content_placements_block_idx' => [
                    'columns' => ['content_block_id', 'is_visible'],
                    'unique' => false,
                ],
            ],
            'revisions' => [
                'content_revisions_block_unique' => [
                    'columns' => ['content_block_id', 'revision'],
                    'unique' => true,
                ],
                'content_revisions_event_idx' => [
                    'columns' => ['event', 'created_at'],
                    'unique' => false,
                ],
                'content_revisions_actor_idx' => [
                    'columns' => ['actor_type', 'actor_id'],
                    'unique' => false,
                ],
            ],
        ];

        foreach ($requirements as $tableKey => $expectedIndexes) {
            $indexes = $schema->getIndexes(ContentConfiguration::table($tableKey));

            foreach ($expectedIndexes as $expectedName => $expected) {
                $matchingIndex = collect($indexes)->first(
                    static fn (array $index): bool => $index['name'] === $expectedName,
                );

                if ($matchingIndex === null
                    || $matchingIndex['columns'] !== $expected['columns']
                    || $matchingIndex['unique'] !== $expected['unique']) {
                    return false;
                }
            }

            $primaryKeyExists = collect($indexes)->contains(
                static fn (array $index): bool => $index['primary']
                    && $index['columns'] === ['id'],
            );

            if (! $primaryKeyExists) {
                return false;
            }
        }

        $definitionIndexes = $schema->getIndexes(ContentConfiguration::table('definitions'));
        $definitionKeyIsUnique = collect($definitionIndexes)->contains(
            static fn (array $index): bool => $index['unique']
                && ! $index['primary']
                && $index['columns'] === ['key'],
        );

        return $definitionKeyIsUnique;
    }

    private function requiredForeignKeysExist(Builder $schema): bool
    {
        /** @var array<string, list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string}>> $requirements */
        $requirements = [
            'blocks' => [[
                'columns' => ['definition_id'],
                'foreign_table' => ContentConfiguration::table('definitions'),
                'foreign_columns' => ['id'],
                'on_delete' => 'restrict',
            ]],
            'blocks_i18n' => [[
                'columns' => ['content_block_id'],
                'foreign_table' => ContentConfiguration::table('blocks'),
                'foreign_columns' => ['id'],
                'on_delete' => 'cascade',
            ]],
            'placements' => [
                [
                    'columns' => ['content_block_id'],
                    'foreign_table' => ContentConfiguration::table('blocks'),
                    'foreign_columns' => ['id'],
                    'on_delete' => 'cascade',
                ],
                [
                    'columns' => ['parent_id'],
                    'foreign_table' => ContentConfiguration::table('placements'),
                    'foreign_columns' => ['id'],
                    'on_delete' => 'set null',
                ],
            ],
            'revisions' => [[
                'columns' => ['content_block_id'],
                'foreign_table' => ContentConfiguration::table('blocks'),
                'foreign_columns' => ['id'],
                'on_delete' => 'cascade',
            ]],
        ];

        foreach ($requirements as $tableKey => $expectedForeignKeys) {
            $foreignKeys = $schema->getForeignKeys(ContentConfiguration::table($tableKey));

            foreach ($expectedForeignKeys as $expected) {
                $exists = collect($foreignKeys)->contains(
                    function (array $foreignKey) use ($expected): bool {
                        $onDelete = $foreignKey['on_delete'];

                        if (! is_string($onDelete)) {
                            return false;
                        }

                        $onDelete = strtolower($onDelete);

                        if ($onDelete === 'no action') {
                            $onDelete = 'restrict';
                        }

                        return $foreignKey['columns'] === $expected['columns']
                            && $foreignKey['foreign_table'] === $expected['foreign_table']
                            && $foreignKey['foreign_columns'] === $expected['foreign_columns']
                            && $onDelete === $expected['on_delete'];
                    },
                );

                if (! $exists) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<ContentDefinitionData>  $definitions
     */
    private function definitionsSynchronized(
        array $definitions,
        CanonicalJson $json,
    ): bool {
        $models = ContentDefinition::query()->get()->keyBy('key');
        $registeredKeys = [];

        foreach ($definitions as $definition) {
            $registeredKeys[] = $definition->key;
            $model = $models->get($definition->key);

            if (! $model instanceof ContentDefinition
                || $model->is_active !== $definition->isActive
                || $model->orphaned_at !== null
                || ! hash_equals($model->source_hash, $json->hash($definition->toArray()))) {
                return false;
            }
        }

        foreach ($models as $key => $model) {
            if (is_string($key) && in_array($key, $registeredKeys, true)) {
                continue;
            }

            if (! is_string($key)
                || $model->is_active
                || $model->orphaned_at === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<ContentDefinitionData>  $definitions
     * @return array{current: bool, paths: bool, pending: int}
     */
    private function definitionMigrationHealth(
        array $definitions,
        ContentDefinitionMigrationRegistry $migrations,
    ): array {
        $sourceVersions = [];

        foreach ($definitions as $definition) {
            $sourceVersions[$definition->key] = $definition->version;
        }

        $mirrors = ContentDefinition::query()
            ->whereIn('key', array_keys($sourceVersions))
            ->get()
            ->keyBy('id');
        $storedVersions = ContentBlock::withTrashed()
            ->select(['definition_id', 'definition_version'])
            ->selectRaw('COUNT(*) as pending_count')
            ->groupBy(['definition_id', 'definition_version'])
            ->get();
        $pending = 0;
        $paths = true;

        foreach ($storedVersions as $stored) {
            $mirror = $mirrors->get($stored->definition_id);

            if (! $mirror instanceof ContentDefinition) {
                continue;
            }

            $targetVersion = $sourceVersions[$mirror->key] ?? null;

            if (! is_int($targetVersion)
                || $stored->definition_version === $targetVersion) {
                continue;
            }

            $pendingCount = $stored->getAttribute('pending_count');

            if (! is_int($pendingCount)
                && (! is_string($pendingCount) || preg_match('/^\d+$/D', $pendingCount) !== 1)) {
                throw new InvalidArgumentException(
                    'Content definition migration diagnostics returned an invalid block count.',
                );
            }

            $pending += (int) $pendingCount;

            if (! $migrations->hasPath(
                $mirror->key,
                $stored->definition_version,
                $targetVersion,
            )) {
                $paths = false;
            }
        }

        return [
            'current' => $pending === 0,
            'paths' => $paths,
            'pending' => $pending,
        ];
    }

    private function placementsUseDeclaredGroups(ContentOwnerRegistry $owners): bool
    {
        $identities = ContentPlacement::query()
            ->select(['owner_type', 'group'])
            ->distinct()
            ->get();

        foreach ($identities as $identity) {
            $ownerType = $identity->getAttribute('owner_type');
            $group = $identity->getAttribute('group');

            if (! is_string($ownerType) || ! is_string($group)) {
                return false;
            }

            $model = $owners->registered($ownerType);

            if ($model === null || ! in_array($group, $owners->groups(new $model), true)) {
                return false;
            }
        }

        return true;
    }
}
