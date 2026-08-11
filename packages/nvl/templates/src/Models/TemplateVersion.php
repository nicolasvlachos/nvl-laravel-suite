<?php

declare(strict_types=1);

namespace Nvl\Templates\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Content\Casts\ContentCompositionSnapshotCast;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Traits\HasContent;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Numbered publication snapshot of a template.
 *
 * @property string $id
 * @property string $template_id
 * @property int $version
 * @property TemplateVersionStatus $status
 * @property array<string, mixed>|null $metadata
 * @property ContentCompositionSnapshotData|null $content_snapshot
 * @property string|null $content_hash
 * @property int $revision
 * @property string|null $published_by_type
 * @property string|null $published_by
 * @property Carbon|null $published_at
 * @property-read Template $template
 */
final class TemplateVersion extends Model implements ContentOwner
{
    use HasContent;
    use HasUuids;

    public const string CONTENT_OWNER_TYPE = 'template-version';

    public const string CONTENT_GROUP = 'document';

    /** @var list<string> */
    protected $fillable = [
        'template_id',
        'version',
        'status',
        'metadata',
        'content_snapshot',
        'content_hash',
        'published_by_type',
        'published_by',
        'published_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'revision' => 1,
    ];

    public function getTable(): string
    {
        return TemplatesConfiguration::table(TemplatesTables::Versions);
    }

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
            'version' => 'integer',
            'status' => TemplateVersionStatus::class,
            'metadata' => 'array',
            'content_snapshot' => ContentCompositionSnapshotCast::class,
            'revision' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Restrict the query to published versions.
     *
     * @param  Builder<static>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', TemplateVersionStatus::Published->value);
    }

    /**
     * Increment the optimistic-lock revision for draft updates.
     */
    protected static function booted(): void
    {
        self::saving(static function (TemplateVersion $version): void {
            if ($version->exists && ! $version->isDirty('revision')) {
                $revision = $version->getOriginal('revision');
                $version->revision = (is_numeric($revision) ? (int) $revision : 0) + 1;
            }
        });
    }
}
