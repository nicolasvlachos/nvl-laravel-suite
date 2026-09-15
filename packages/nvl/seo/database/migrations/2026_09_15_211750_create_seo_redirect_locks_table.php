<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Seo\Definitions\Tables\SeoTables;

return new class extends Migration
{
    /**
     * Create the stable mutex row boundary for redirect graph mutations.
     */
    public function up(): void
    {
        Schema::create(SeoTables::RedirectLocks, function (Blueprint $table): void {
            $table->string('name', 32)->primary();
        });
    }

    /**
     * Drop the redirect graph mutex table.
     */
    public function down(): void
    {
        Schema::dropIfExists(SeoTables::RedirectLocks);
    }
};
