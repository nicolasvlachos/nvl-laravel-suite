<?php

declare(strict_types=1);

namespace Nvl\Templates\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable as HasTranslations;

/**
 * Structural, renderer-bound template aggregate.
 *
 * @property string $id
 * @property string $key
 * @property string $renderer
 * @property TemplateStatus $status
 * @property array<string, mixed>|null $schema
 * @property array<string, mixed>|null $metadata
 * @property int $revision
 * @property-read Collection<int, TemplateTranslation> $translations
 * @property-read Collection<int, TemplateVersion> $versions
 * @property-read Collection<int, TemplateAssignment> $assignments
 */
final class Template extends Model implements TranslatableModel
{
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'renderer',
        'status',
        'schema',
        'metadata',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'revision' => 1,
    ];

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: TemplateTranslation::class,
            foreignKey: 'template_id',
            fields: ['title', 'description'],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    public function getTable(): string
    {
        return TemplatesConfiguration::table(TemplatesTables::Templates);
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
            'status' => TemplateStatus::class,
            'schema' => 'array',
            'metadata' => 'array',
            'revision' => 'integer',
        ];
    }

    /**
     * @return HasMany<TemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasMany<TemplateAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TemplateAssignment::class);
    }

    /**
     * Increment the optimistic-lock revision for ordinary updates.
     */
    protected static function booted(): void
    {
        self::saving(static function (Template $template): void {
            if ($template->exists && ! $template->isDirty('revision')) {
                $revision = $template->getOriginal('revision');
                $template->revision = (is_numeric($revision) ? (int) $revision : 0) + 1;
            }
        });
    }
}
