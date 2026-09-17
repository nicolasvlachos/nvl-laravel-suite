<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the fixture-owned tenant roots and queue observation ledger. */
    public function up(): void
    {
        Schema::create('tenant_articles', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('slug');
            $table->string('title');
            $table->timestamps();
            $table->unique(['tenant_id', 'slug'], 'tenant_articles_tenant_slug_unique');
        });

        Schema::create('tenant_probe_observations', static function (Blueprint $table): void {
            $table->id();
            $table->string('probe', 120)->index();
            $table->string('phase', 20);
            $table->uuid('tenant_id');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'probe'], 'tenant_probe_tenant_probe_index');
        });
    }

    /** Remove fixture-owned observation and tenant root tables. */
    public function down(): void
    {
        Schema::dropIfExists('tenant_probe_observations');
        Schema::dropIfExists('tenant_articles');
    }
};
