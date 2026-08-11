<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Support\TemplatesConfiguration;

return new class extends Migration
{
    /**
     * Create idempotent queued render records.
     */
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $tableName = TemplatesConfiguration::table(TemplatesTables::Renders);

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Template renders table [{$tableName}] already exists; disable templates.migrations.enabled during controlled schema adoption.",
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('template_id');
            $table->uuid('template_version_id');
            $table->uuid('template_assignment_id')->nullable();
            $table->string('locale', 35);
            $table->string('profile', 100)->default('default');
            $table->longText('settings')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->string('payload_digest', 64);
            $table->longText('payload')->nullable();
            $table->string('requested_by_type')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('output_name')->nullable();
            $table->string('output_mime_type')->nullable();
            $table->text('failure')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('dispatch_generation')->default(0);
            $table->uuid('processing_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on(TemplatesConfiguration::table(TemplatesTables::Templates))
                ->cascadeOnDelete();
            $table->foreign('template_version_id')
                ->references('id')
                ->on(TemplatesConfiguration::table(TemplatesTables::Versions))
                ->cascadeOnDelete();
            $table->foreign('template_assignment_id')
                ->references('id')
                ->on(TemplatesConfiguration::table(TemplatesTables::Assignments))
                ->nullOnDelete();
            $table->index(['status', 'created_at'], 'template_renders_status_created_idx');
            $table->index(
                ['status', 'lease_expires_at'],
                'template_renders_status_lease_idx',
            );
            $table->index(
                ['status', 'updated_at'],
                'template_renders_status_updated_idx',
            );
            $table->index(
                ['requested_by_type', 'requested_by', 'created_at'],
                'template_renders_requester_idx',
            );
        });
    }

    /**
     * Drop persisted render records.
     */
    public function down(): void
    {
        Schema::connection(TemplatesConfiguration::connection())
            ->dropIfExists(TemplatesConfiguration::table(TemplatesTables::Renders));
    }
};
