<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string CONNECTION_NAME = 'activity_consumer';

    private const string TABLE_NAME = 'activity_consumer_activity_log';

    public function up(): void
    {
        Schema::connection(self::CONNECTION_NAME)->create(self::TABLE_NAME, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index(['created_at', 'id']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION_NAME)->dropIfExists(self::TABLE_NAME);
    }
};
