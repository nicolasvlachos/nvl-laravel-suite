<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Pages\Definitions\Tables\PagesTables;

return new class extends Migration
{
    /**
     * Create the stable per-site rows used to serialize page-tree mutations.
     */
    public function up(): void
    {
        $connection = config('pages.connection');
        $schema = Schema::connection(is_string($connection) ? $connection : null);
        $tableName = (string) config('pages.tables.page_tree_locks', PagesTables::TreeLocks);

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->string('site', 64)->primary();
        });
    }

    /**
     * Drop the page-tree serialization table.
     */
    public function down(): void
    {
        $connection = config('pages.connection');

        Schema::connection(is_string($connection) ? $connection : null)
            ->dropIfExists((string) config('pages.tables.page_tree_locks', PagesTables::TreeLocks));
    }
};
