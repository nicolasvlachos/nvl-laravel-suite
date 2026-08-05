<?php

declare(strict_types=1);

namespace Nvl\Seo\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;
use Nvl\Seo\Database\Factories\SeoProfileFactory;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Seo\Support\SeoModelIdentifier;
use Nvl\Seo\Support\SeoScope;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * SEO settings attached to one model in one site scope.
 *
 * @property string $id
 * @property string $scope
 * @property string $seoable_type
 * @property string $seoable_id
 * @property bool $is_indexable
 * @property bool $is_followable
 * @property int|null $max_snippet
 * @property string|null $max_image_preview
 * @property int|null $max_video_preview
 * @property bool $sitemap_included
 * @property string|null $sitemap_priority
 * @property SitemapChangeFrequency|null $sitemap_change_frequency
 * @property array<string, mixed>|null $metadata
 * @property int $revision
 * @property string $status
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $seoable
 * @property-read Collection<int, SeoProfileTranslation> $translations
 *
 * @method static Builder<static> forOwner(Model $owner, ?string $scope = null)
 */
final class SeoProfile extends Model implements TranslatableModel
{
    /** @use HasFactory<SeoProfileFactory> */
    use HasFactory;

    use HasUuids;
    use Translatable;

    protected $table = SeoTables::Profiles;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope' => 'default',
        'is_indexable' => true,
        'is_followable' => true,
        'max_image_preview' => 'large',
        'sitemap_included' => true,
        'revision' => 1,
        'status' => 'active',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'seoable_type',
        'seoable_id',
        'is_indexable',
        'is_followable',
        'max_snippet',
        'max_image_preview',
        'max_video_preview',
        'sitemap_included',
        'sitemap_priority',
        'sitemap_change_frequency',
        'metadata',
        'revision',
        'status',
        'archived_at',
    ];

    /**
     * Define the polymorphic SEO owner.
     *
     * @return MorphTo<Model, $this>
     */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Restrict a query to one owner and site scope.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForOwner(Builder $query, Model $owner, ?string $scope = null): void
    {
        $query
            ->where('seoable_type', $owner->getMorphClass())
            ->where('seoable_id', SeoModelIdentifier::required($owner))
            ->where('scope', SeoScope::normalize($scope));
    }

    /**
     * Restrict runtime resolution to active profiles.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active')->whereNull('archived_at');
    }

    /**
     * Define locale-specific discoverability copy.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: SeoProfileTranslation::class,
            foreignKey: 'seo_profile_id',
            fields: [
                'path',
                'title',
                'description',
                'canonical_url',
                'image_url',
                'image_reference',
                'image_alt',
                'open_graph_title',
                'open_graph_description',
                'twitter_title',
                'twitter_description',
                'twitter_card',
                'structured_data',
                'metadata',
            ],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_indexable' => 'boolean',
            'is_followable' => 'boolean',
            'max_snippet' => 'integer',
            'max_video_preview' => 'integer',
            'sitemap_included' => 'boolean',
            'sitemap_priority' => 'decimal:1',
            'sitemap_change_frequency' => SitemapChangeFrequency::class,
            'metadata' => 'array',
            'revision' => 'integer',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Guard scope as part of the profile's immutable identity.
     */
    protected static function booted(): void
    {
        self::creating(function (SeoProfile $profile): void {
            $profile->scope = SeoScope::normalize($profile->scope);
        });

        self::updating(function (SeoProfile $profile): void {
            if ($profile->isDirty('scope')) {
                throw new LogicException('An SEO profile scope is immutable; create a profile in the target scope.');
            }

            if (! $profile->isDirty('revision')) {
                $original = $profile->getOriginal('revision');
                $profile->revision = is_numeric($original) ? ((int) $original) + 1 : 1;
            }
        });
    }

    /**
     * Create the package-owned model factory.
     */
    protected static function newFactory(): SeoProfileFactory
    {
        return SeoProfileFactory::new();
    }
}
