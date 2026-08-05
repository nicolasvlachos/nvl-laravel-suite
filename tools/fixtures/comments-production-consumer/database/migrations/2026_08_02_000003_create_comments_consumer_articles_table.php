<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE_NAME = 'comments_consumer_articles';

    /**
     * Create the consumer-owned exact string-key comment targets.
     */
    public function up(): void
    {
        Schema::create(self::TABLE_NAME, function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->string('title');
            $table->timestamps();
        });
    }

    /**
     * Drop the consumer-owned target table.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }
};
