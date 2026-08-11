<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(TranslationsTables::ScanRuns, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('scanned_at', 6)->index();
            $table->unsignedInteger('files');
            $table->unsignedBigInteger('hits');
            $table->timestampsTz(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TranslationsTables::ScanRuns);
    }
};
