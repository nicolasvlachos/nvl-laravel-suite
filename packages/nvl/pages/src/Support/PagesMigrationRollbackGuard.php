<?php

declare(strict_types=1);

namespace Nvl\Pages\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Schema\Builder;
use LogicException;
use Nvl\Pages\Definitions\Tables\PagesTables;
use ReflectionClass;

/**
 * Makes the released self-referencing Pages migration reversible on SQLite.
 */
final class PagesMigrationRollbackGuard
{
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * Remove only the internal hierarchy links before SQLite drops the table.
     */
    public function before(MigrationStarted $event): void
    {
        if (! $this->isPagesTableRollback($event)) {
            return;
        }

        $connection = $this->database->connection(PagesConfiguration::connection());

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $schema = $connection->getSchemaBuilder();
        $table = PagesConfiguration::table('pages', PagesTables::Pages);
        $tablePrefix = $connection->getTablePrefix();
        $physicalTable = $this->physicalTable($table, $tablePrefix);

        if (! $schema->hasTable($table)
            || ! $this->hasSelfReference($schema->getForeignKeys($table), $physicalTable)) {
            return;
        }

        $this->ensureNoExternalReferences($connection, $schema, $table, $tablePrefix);
        $connection->table($table)
            ->whereNotNull('parent_id')
            ->update(['parent_id' => null]);
    }

    /**
     * Keep host-owned references authoritative instead of bypassing them.
     */
    private function ensureNoExternalReferences(
        Connection $connection,
        Builder $schema,
        string $table,
        string $tablePrefix,
    ): void {
        $target = $this->physicalTable($table, $tablePrefix);

        foreach ($schema->getTableListing() as $candidate) {
            $physicalCandidate = $this->unqualifiedTable($candidate);

            if ($this->sameTable($physicalCandidate, $target)) {
                continue;
            }

            foreach ($this->foreignTables($connection, $physicalCandidate) as $foreignTable) {
                if ($this->sameTable($foreignTable, $target)) {
                    throw new LogicException(
                        "Cannot roll back Pages while table [{$candidate}] references [{$table}].",
                    );
                }
            }
        }
    }

    private function physicalTable(string $logicalTable, string $tablePrefix): string
    {
        return $tablePrefix.$this->unqualifiedTable($logicalTable);
    }

    /**
     * Read SQLite metadata by physical table name without reapplying a connection prefix.
     *
     * @return list<string>
     */
    private function foreignTables(Connection $connection, string $physicalTable): array
    {
        $foreignTables = [];

        foreach ($connection->select(
            'select "table" as foreign_table from pragma_foreign_key_list(?)',
            [$physicalTable],
        ) as $foreignKey) {
            if (! is_object($foreignKey)) {
                continue;
            }

            $foreignTable = get_object_vars($foreignKey)['foreign_table'] ?? null;

            if (is_string($foreignTable)) {
                $foreignTables[] = $foreignTable;
            }
        }

        return $foreignTables;
    }

    private function unqualifiedTable(string $table): string
    {
        $separator = strrpos($table, '.');

        return $separator === false ? $table : substr($table, $separator + 1);
    }

    private function sameTable(string $first, string $second): bool
    {
        return strtolower($this->unqualifiedTable($first))
            === strtolower($this->unqualifiedTable($second));
    }

    /**
     * @param  list<array{
     *     name: string|null,
     *     columns: list<string>,
     *     foreign_schema: string|null,
     *     foreign_table: string,
     *     foreign_columns: list<string>,
     *     on_update: string|null,
     *     on_delete: string|null
     * }>  $foreignKeys
     */
    private function hasSelfReference(array $foreignKeys, string $physicalTable): bool
    {
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['columns'] === ['parent_id']
                && $this->sameTable($foreignKey['foreign_table'], $physicalTable)
                && $foreignKey['foreign_columns'] === ['id']) {
                return true;
            }
        }

        return false;
    }

    private function isPagesTableRollback(MigrationStarted $event): bool
    {
        if ($event->method !== 'down') {
            return false;
        }

        $migrationFile = (new ReflectionClass($event->migration))->getFileName();
        $releasedMigrationFile = dirname(__DIR__, 2)
            .'/database/migrations/2026_07_28_100001_create_pages_table.php';

        if (! is_string($migrationFile)
            || ! is_file($migrationFile)
            || ! is_file($releasedMigrationFile)) {
            return false;
        }

        $migrationHash = hash_file('sha256', $migrationFile);
        $releasedMigrationHash = hash_file('sha256', $releasedMigrationFile);

        return is_string($migrationHash)
            && is_string($releasedMigrationHash)
            && hash_equals($releasedMigrationHash, $migrationHash);
    }
}
