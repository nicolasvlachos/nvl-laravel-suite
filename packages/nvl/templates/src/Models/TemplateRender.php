<?php

declare(strict_types=1);

namespace Nvl\Templates\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\Traits\InteractsWithMedia;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Idempotent persisted render request with a private one-to-one output.
 *
 * @property string $id
 * @property string $template_id
 * @property string $template_version_id
 * @property string|null $template_assignment_id
 * @property string $locale
 * @property string $profile
 * @property array<string, mixed>|null $settings
 * @property TemplateRenderStatus $status
 * @property string|null $idempotency_key
 * @property string $payload_digest
 * @property array<string, mixed>|null $payload
 * @property string|null $requested_by_type
 * @property string|null $requested_by
 * @property string|null $output_name
 * @property string|null $output_mime_type
 * @property string|null $failure
 * @property int $attempts
 * @property int $dispatch_generation
 * @property string|null $processing_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Template $template
 * @property-read TemplateVersion $version
 * @property-read TemplateAssignment|null $assignment
 * @property-read Collection<int, Media> $media
 */
final class TemplateRender extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;

    /** @var array<string, int|string> */
    protected $attributes = [
        'profile' => 'default',
        'status' => 'pending',
        'attempts' => 0,
        'dispatch_generation' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'template_id',
        'template_version_id',
        'template_assignment_id',
        'locale',
        'profile',
        'settings',
        'status',
        'idempotency_key',
        'payload_digest',
        'payload',
        'requested_by_type',
        'requested_by',
        'output_name',
        'output_mime_type',
        'failure',
        'attempts',
        'dispatch_generation',
        'processing_token',
        'lease_expires_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'payload',
        'settings',
        'failure',
        'dispatch_generation',
        'processing_token',
    ];

    /**
     * Return the configured render-record table name.
     */
    public function getTable(): string
    {
        return TemplatesConfiguration::table('template_renders');
    }

    /**
     * Return the configured package database connection.
     */
    public function getConnectionName(): ?string
    {
        return TemplatesConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TemplateRenderStatus::class,
            'payload' => 'encrypted:array',
            'settings' => 'encrypted:array',
            'attempts' => 'integer',
            'dispatch_generation' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Render outputs are private and atomically replaceable.
     */
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('output')->oneToOne();
    }

    /**
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * @return BelongsTo<TemplateVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    /**
     * @return BelongsTo<TemplateAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TemplateAssignment::class, 'template_assignment_id');
    }
}
