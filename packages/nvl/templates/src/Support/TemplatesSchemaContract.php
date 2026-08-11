<?php

declare(strict_types=1);

namespace Nvl\Templates\Support;

use Illuminate\Database\Schema\Builder;
use Nvl\Templates\Definitions\Tables\TemplatesTables;

/**
 * Defines and inspects the frozen canonical Templates schema ownership contract.
 */
final class TemplatesSchemaContract
{
    /**
     * @return array<string, array{
     *   creator: string,
     *   columns: list<string>,
     *   indexes: array<string, array{columns: list<string>, unique: bool}>,
     *   foreign_keys: list<array{columns: list<string>, target: string, foreign_columns: list<string>, on_delete: string}>
     * }>
     */
    public static function tables(): array
    {
        return [
            TemplatesTables::Templates => [
                'creator' => '2026_07_27_100001_create_templates_table',
                'columns' => ['id', 'key', 'renderer', 'status', 'schema', 'metadata', 'revision', 'created_at', 'updated_at'],
                'indexes' => [
                    'templates_key_unique' => ['columns' => ['key'], 'unique' => true],
                    'templates_renderer_index' => ['columns' => ['renderer'], 'unique' => false],
                    'templates_status_index' => ['columns' => ['status'], 'unique' => false],
                    'templates_status_updated_idx' => ['columns' => ['status', 'updated_at'], 'unique' => false],
                ],
                'foreign_keys' => [],
            ],
            TemplatesTables::I18n => [
                'creator' => '2026_07_27_100002_create_templates_i18n_table',
                'columns' => ['id', 'template_id', 'locale', 'title', 'description', 'created_at', 'updated_at'],
                'indexes' => [
                    'templates_i18n_owner_locale_unique' => ['columns' => ['template_id', 'locale'], 'unique' => true],
                    'templates_i18n_locale_title_idx' => ['columns' => ['locale', 'title'], 'unique' => false],
                ],
                'foreign_keys' => [[
                    'columns' => ['template_id'],
                    'target' => TemplatesTables::Templates,
                    'foreign_columns' => ['id'],
                    'on_delete' => 'cascade',
                ]],
            ],
            TemplatesTables::Versions => [
                'creator' => '2026_07_27_100003_create_template_versions_table',
                'columns' => ['id', 'template_id', 'version', 'status', 'metadata', 'content_snapshot', 'content_hash', 'revision', 'published_by_type', 'published_by', 'published_at', 'created_at', 'updated_at'],
                'indexes' => [
                    'template_versions_number_unique' => ['columns' => ['template_id', 'version'], 'unique' => true],
                    'template_versions_resolution_idx' => ['columns' => ['template_id', 'status', 'version'], 'unique' => false],
                    'template_versions_publisher_idx' => ['columns' => ['published_by_type', 'published_by'], 'unique' => false],
                ],
                'foreign_keys' => [[
                    'columns' => ['template_id'],
                    'target' => TemplatesTables::Templates,
                    'foreign_columns' => ['id'],
                    'on_delete' => 'cascade',
                ]],
            ],
            TemplatesTables::Assignments => [
                'creator' => '2026_07_27_100006_create_template_assignments_table',
                'columns' => ['id', 'template_id', 'template_version_id', 'owner_type', 'owner_id', 'profile', 'settings', 'revision', 'created_at', 'updated_at'],
                'indexes' => [
                    'template_assignments_owner_profile_unique' => ['columns' => ['owner_type', 'owner_id', 'profile'], 'unique' => true],
                    'template_assignments_template_version_idx' => ['columns' => ['template_id', 'template_version_id'], 'unique' => false],
                ],
                'foreign_keys' => [
                    [
                        'columns' => ['template_id'],
                        'target' => TemplatesTables::Templates,
                        'foreign_columns' => ['id'],
                        'on_delete' => 'cascade',
                    ],
                    [
                        'columns' => ['template_version_id'],
                        'target' => TemplatesTables::Versions,
                        'foreign_columns' => ['id'],
                        'on_delete' => 'set null',
                    ],
                ],
            ],
            TemplatesTables::Renders => [
                'creator' => '2026_07_27_100007_create_template_renders_table',
                'columns' => ['id', 'template_id', 'template_version_id', 'template_assignment_id', 'locale', 'profile', 'settings', 'status', 'idempotency_key', 'payload_digest', 'payload', 'requested_by_type', 'requested_by', 'output_name', 'output_mime_type', 'failure', 'attempts', 'dispatch_generation', 'processing_token', 'lease_expires_at', 'started_at', 'completed_at', 'failed_at', 'created_at', 'updated_at'],
                'indexes' => [
                    'template_renders_idempotency_key_unique' => ['columns' => ['idempotency_key'], 'unique' => true],
                    'template_renders_status_created_idx' => ['columns' => ['status', 'created_at'], 'unique' => false],
                    'template_renders_status_lease_idx' => ['columns' => ['status', 'lease_expires_at'], 'unique' => false],
                    'template_renders_status_updated_idx' => ['columns' => ['status', 'updated_at'], 'unique' => false],
                    'template_renders_requester_idx' => ['columns' => ['requested_by_type', 'requested_by', 'created_at'], 'unique' => false],
                ],
                'foreign_keys' => [
                    [
                        'columns' => ['template_id'],
                        'target' => TemplatesTables::Templates,
                        'foreign_columns' => ['id'],
                        'on_delete' => 'cascade',
                    ],
                    [
                        'columns' => ['template_version_id'],
                        'target' => TemplatesTables::Versions,
                        'foreign_columns' => ['id'],
                        'on_delete' => 'cascade',
                    ],
                    [
                        'columns' => ['template_assignment_id'],
                        'target' => TemplatesTables::Assignments,
                        'foreign_columns' => ['id'],
                        'on_delete' => 'set null',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{columns: list<string>, indexes: list<string>, constraints: list<string>}
     */
    public static function issues(Builder $schema, string $alias): array
    {
        $contract = self::tables()[$alias];
        $table = TemplatesConfiguration::table($alias);
        $missingColumns = array_values(array_filter(
            $contract['columns'],
            static fn (string $column): bool => ! $schema->hasColumn($table, $column),
        ));
        $actualIndexes = [];

        foreach ($schema->getIndexes($table) as $index) {
            $actualIndexes[$index['name']] = $index;
        }

        $invalidIndexes = [];

        foreach ($contract['indexes'] as $name => $expected) {
            $actual = $actualIndexes[$name] ?? null;

            if ($actual === null
                || $actual['columns'] !== $expected['columns']
                || $actual['unique'] !== $expected['unique']) {
                $invalidIndexes[] = $name;
            }
        }

        $hasPrimaryId = false;

        foreach ($actualIndexes as $index) {
            if ($index['primary'] && $index['columns'] === ['id']) {
                $hasPrimaryId = true;
                break;
            }
        }

        $invalidConstraints = $hasPrimaryId ? [] : ['primary(id)'];
        $actualForeignKeys = $schema->getForeignKeys($table);

        foreach ($contract['foreign_keys'] as $foreignKey) {
            $matched = false;

            foreach ($actualForeignKeys as $actual) {
                if ($actual['columns'] === $foreignKey['columns']
                    && $actual['foreign_table'] === TemplatesConfiguration::table($foreignKey['target'])
                    && $actual['foreign_columns'] === $foreignKey['foreign_columns']
                    && self::action($actual['on_delete']) === $foreignKey['on_delete']) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $invalidConstraints[] = sprintf(
                    '%s->%s(%s) on delete %s',
                    implode(',', $foreignKey['columns']),
                    TemplatesConfiguration::table($foreignKey['target']),
                    implode(',', $foreignKey['foreign_columns']),
                    $foreignKey['on_delete'],
                );
            }
        }

        return [
            'columns' => $missingColumns,
            'indexes' => $invalidIndexes,
            'constraints' => $invalidConstraints,
        ];
    }

    private static function action(?string $action): string
    {
        return str_replace('_', ' ', mb_strtolower($action ?? ''));
    }
}
