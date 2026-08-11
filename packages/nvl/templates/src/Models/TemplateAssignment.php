<?php

declare(strict_types=1);

namespace Nvl\Templates\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Explicit owner/profile mapping to a template and optional pinned version.
 *
 * @property string $id
 * @property string $template_id
 * @property string|null $template_version_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $profile
 * @property array<string, mixed>|null $settings
 * @property int $revision
 */
final class TemplateAssignment extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'template_id',
        'template_version_id',
        'owner_type',
        'owner_id',
        'profile',
        'settings',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['profile' => 'default', 'revision' => 1];

    public function getTable(): string
    {
        return TemplatesConfiguration::table(TemplatesTables::Assignments);
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
        return ['settings' => 'array', 'revision' => 'integer'];
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

    protected static function booted(): void
    {
        self::saving(static function (TemplateAssignment $assignment): void {
            if ($assignment->exists && ! $assignment->isDirty('revision')) {
                $revision = $assignment->getOriginal('revision');
                $assignment->revision = (is_numeric($revision) ? (int) $revision : 0) + 1;
            }
        });
    }
}
