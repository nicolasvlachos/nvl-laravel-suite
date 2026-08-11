<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Pages\Definitions\Tables\PagesTables;

return new class extends Migration
{
    /**
     * Create the canonical structural pages table.
     */
    public function up(): void
    {
        $connection = config('pages.connection');
        $schema = Schema::connection(is_string($connection) ? $connection : null);
        $tableName = (string) config('pages.tables.pages', PagesTables::Pages);

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->uuid('id');
            $table->primary('id');
            $table->uuid('parent_id')->nullable();
            $table->string('parent_key', 36)->default('__root__');
            $table->string('key', 191)->unique();
            $table->string('site', 64)->default('default');
            $table->string('slug', 191);
            $table->string('path', 768);
            $table->char('path_hash', 64);
            $table->string('kind', 32)->default('static');
            $table->string('resource', 100)->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_navigable')->default(true);
            $table->boolean('sitemap_included')->default(true);
            $table->decimal('sitemap_priority', 2, 1)->nullable();
            $table->string('sitemap_change_frequency', 16)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site', 'parent_key', 'slug'], 'pages_site_sibling_slug_unique');
            $table->unique(['site', 'path_hash'], 'pages_site_path_hash_unique');
            $table->index(['site', 'status', 'published_at', 'expires_at'], 'pages_public_lookup_index');
            $table->index(['site', 'parent_id', 'position'], 'pages_tree_order_index');
            $table->index(['kind', 'resource'], 'pages_resource_lookup_index');
            $table->foreign('parent_id')
                ->references('id')
                ->on($tableName)
                ->restrictOnDelete();
        });
    }

    /**
     * Drop the structural pages table.
     */
    public function down(): void
    {
        $connection = config('pages.connection');
        Schema::connection(is_string($connection) ? $connection : null)
            ->dropIfExists((string) config('pages.tables.pages', PagesTables::Pages));
    }
};
