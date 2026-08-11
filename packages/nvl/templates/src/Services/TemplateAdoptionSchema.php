<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Media\Models\Media;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Inventories canonical/staging schemas and removes staging index collisions.
 *
 * @phpstan-type CanonicalTable array{connection: string, exists: bool}
 * @phpstan-type SchemaInventory array{driver: string, staging: array<string, array{exists: bool, columns: list<string>, indexes: list<array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}>}>, canonical: array<string, CanonicalTable>}
 */
final class TemplateAdoptionSchema
{
    /**
     * @param  list<string>  $stagingTables
     * @return SchemaInventory
     */
    public function inventory(string $connection, array $stagingTables): array
    {
        $stagingSchema = Schema::connection($connection);
        $staging = [];

        foreach ($stagingTables as $table) {
            $exists = $stagingSchema->hasTable($table);
            $staging[$table] = [
                'exists' => $exists,
                'columns' => $exists ? $stagingSchema->getColumnListing($table) : [],
                'indexes' => $exists ? $stagingSchema->getIndexes($table) : [],
            ];
        }

        return [
            'driver' => $stagingSchema->getConnection()->getDriverName(),
            'staging' => $staging,
            'canonical' => $this->canonicalInventory(),
        ];
    }

    /**
     * @param  array<string, CanonicalTable>  $inventory
     */
    public function assertCanonical(array $inventory): void
    {
        $missing = array_keys(array_filter(
            $inventory,
            static fn (array $table): bool => ! $table['exists'],
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Cannot apply adoption before canonical tables exist: '.implode(', ', $missing).'.',
            );
        }
    }

    /**
     * @param  list<string>  $tables
     * @return list<array{table: string, index: string, operation: string}>
     */
    public function prepare(string $connection, array $tables): array
    {
        $schema = Schema::connection($connection);
        $operations = [];

        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                throw new InvalidArgumentException("Declared staging table [{$table}] does not exist.");
            }

            foreach ($schema->getIndexes($table) as $index) {
                $name = $index['name'];

                if ($name === ''
                    || $index['primary']
                    || str_starts_with($name, 'sqlite_autoindex_')) {
                    continue;
                }

                $schema->table($table, static function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
                $operations[] = [
                    'table' => $table,
                    'index' => $name,
                    'operation' => 'dropped',
                ];
            }
        }

        return $operations;
    }

    /**
     * @return array<string, CanonicalTable>
     */
    private function canonicalInventory(): array
    {
        $default = config('database.default');

        if (! is_string($default) || $default === '') {
            throw new InvalidArgumentException('database.default must be a connection name.');
        }

        $templateConnection = TemplatesConfiguration::connection() ?? $default;
        $contentConnection = ContentConfiguration::connection() ?? $default;
        $templates = Schema::connection($templateConnection);
        $content = Schema::connection($contentConnection);
        $mediaModel = new Media;
        $mediaConnection = $mediaModel->getConnectionName() ?? $default;
        $media = Schema::connection($mediaConnection);
        $inventory = [];

        foreach ([
            TemplatesTables::Templates,
            TemplatesTables::I18n,
            TemplatesTables::Versions,
            TemplatesTables::Assignments,
            TemplatesTables::Renders,
        ] as $alias) {
            $inventory["templates.{$alias}"] = [
                'connection' => $templateConnection,
                'exists' => $templates->hasTable(TemplatesConfiguration::table($alias)),
            ];
        }

        foreach (['definitions', 'blocks', 'blocks_i18n'] as $alias) {
            $inventory["content.{$alias}"] = [
                'connection' => $contentConnection,
                'exists' => $content->hasTable(ContentConfiguration::table($alias)),
            ];
        }

        $inventory['media.media'] = [
            'connection' => $mediaConnection,
            'exists' => $media->hasTable($mediaModel->getTable()),
        ];

        return $inventory;
    }
}
