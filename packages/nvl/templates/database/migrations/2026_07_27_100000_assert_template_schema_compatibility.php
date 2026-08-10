<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Templates\Support\TemplatesSchemaContract;

return new class extends Migration
{
    /**
     * Refuse unowned or structurally incomplete canonical Templates tables.
     */
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $ran = $this->ranMigrations();

        foreach (TemplatesSchemaContract::tables() as $alias => $contract) {
            $table = TemplatesConfiguration::table($alias);
            $exists = $schema->hasTable($table);
            $creatorRecorded = in_array($contract['creator'], $ran, true);

            if ($exists && ! $creatorRecorded) {
                throw new LogicException(
                    "Templates table [{$table}] already exists without package creator [{$contract['creator']}]; disable templates.migrations.enabled and run the staged adoption preflight.",
                );
            }

            if (! $exists && $creatorRecorded) {
                throw new LogicException(
                    "Templates creator [{$contract['creator']}] is recorded but table [{$table}] is missing.",
                );
            }

            if ($exists) {
                $issues = TemplatesSchemaContract::issues($schema, $alias);

                if ($issues['columns'] !== []
                    || $issues['indexes'] !== []
                    || $issues['constraints'] !== []) {
                    throw new LogicException(sprintf(
                        'Templates table [%s] is incompatible; missing or invalid columns [%s], indexes [%s], constraints [%s].',
                        $table,
                        implode(', ', $issues['columns']),
                        implode(', ', $issues['indexes']),
                        implode(', ', $issues['constraints']),
                    ));
                }
            }
        }
    }

    /**
     * Leave the read-only compatibility preflight reversible through history only.
     */
    public function down(): void {}

    /**
     * Return exact package migration-history names.
     *
     * @return list<string>
     */
    private function ranMigrations(): array
    {
        $migrator = app(Migrator::class);

        return $migrator->repositoryExists()
            ? array_values($migrator->getRepository()->getRan())
            : [];
    }
};
