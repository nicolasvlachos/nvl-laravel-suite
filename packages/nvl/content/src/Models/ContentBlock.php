<?php

declare(strict_types=1);

namespace Nvl\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Content\Casts\ContentSchemaCast;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Reusable localized content values for one definition and scope.
 *
 * @property string $id
 * @property string $definition_id
 * @property string $key
 * @property string $scope
 * @property string $scope_key
 * @property ContentStatus $status
 * @property ContentVisibility $visibility
 * @property array<string, mixed>|null $values
 * @property array<string, mixed>|null $metadata
 * @property int $definition_version
 * @property string $definition_hash
 * @property ContentSchema $definition_schema
 * @property string|null $definition_view
 * @property int $revision
 * @property string|null $published_by_type
 * @property string|null $published_by_id
 * @property Carbon|null $published_at
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 * @property string|null $updated_by_type
 * @property string|null $updated_by_id
 * @property-read ContentDefinition $definition
 * @property-read Collection<int, ContentBlockTranslation> $translations
 * @property-read Collection<int, ContentPlacement> $placements
 * @property-read Collection<int, ContentRevision> $revisions
 */
final class ContentBlock extends Model implements TranslatableModel
{
    use HasUuids;
    use SoftDeletes;
    use Translatable;

    /** @var list<string> */
    protected $fillable = [
        'definition_id',
        'key',
        'scope',
        'scope_key',
        'status',
        'visibility',
        'values',
        'metadata',
        'definition_version',
        'definition_hash',
        'definition_schema',
        'definition_view',
        'revision',
        'published_by_type',
        'published_by_id',
        'published_at',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'scope' => 'global',
        'scope_key' => '*',
        'status' => 'draft',
        'visibility' => 'public',
        'revision' => 1,
    ];

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        $locales = ContentConfiguration::stringList('content.locales.available');

        return new RelatedTranslationDefinition(
            translationModel: ContentBlockTranslation::class,
            foreignKey: 'content_block_id',
            fields: ['values'],
            locales: $locales !== [] ? $locales : null,
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    public function getTable(): string
    {
        return ContentConfiguration::table('blocks');
    }

    public function getConnectionName(): ?string
    {
        return ContentConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'visibility' => ContentVisibility::class,
            'values' => 'array',
            'metadata' => 'array',
            'definition_version' => 'integer',
            'definition_schema' => ContentSchemaCast::class,
            'revision' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ContentDefinition::class, 'definition_id');
    }

    /**
     * @return HasMany<ContentPlacement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(ContentPlacement::class);
    }

    /**
     * @return HasMany<ContentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class)->orderByDesc('revision');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ContentStatus::Published->value);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('visibility', ContentVisibility::Public->value);
    }

    public static function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition('definition', 'definition_id'),
                new FilterDefinition('key', 'key'),
                new FilterDefinition('scope', 'scope'),
                new FilterDefinition('scope_key', 'scope_key'),
                new FilterDefinition(
                    alias: 'status',
                    column: 'status',
                    type: FilterValueType::Enum,
                    operators: [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(ContentStatus::cases(), 'value'),
                ),
                new FilterDefinition(
                    alias: 'visibility',
                    column: 'visibility',
                    type: FilterValueType::Enum,
                    enumValues: array_column(ContentVisibility::cases(), 'value'),
                ),
            ],
            sorts: [
                new SortDefinition('key', 'key'),
                new SortDefinition('updated', 'updated_at'),
                new SortDefinition('published', 'published_at'),
                new SortDefinition('id', 'id'),
            ],
            defaultSorts: ['-updated', 'key'],
            tieBreakerSort: 'id',
        );
    }
}
