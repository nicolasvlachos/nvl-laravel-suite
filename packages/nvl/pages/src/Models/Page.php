<?php

declare(strict_types=1);

namespace Nvl\Pages\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Support\PagePath;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Seo\Enums\SitemapChangeFrequency;
use Nvl\Seo\Traits\HasSeo;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Structural page node whose editable copy is stored in dedicated locale rows.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $parent_key
 * @property string $key
 * @property string $site
 * @property string $slug
 * @property string $path
 * @property string $path_hash
 * @property PageKind $kind
 * @property string|null $resource
 * @property PageStatus $status
 * @property int $position
 * @property bool $is_navigable
 * @property bool $sitemap_included
 * @property string|null $sitemap_priority
 * @property SitemapChangeFrequency|null $sitemap_change_frequency
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property int $revision
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Page|null $parent
 * @property-read Collection<int, Page> $children
 * @property-read Collection<int, PageTranslation> $translations
 *
 * @method static Builder<static> publiclyVisible(?Carbon $at = null)
 * @method static Builder<static> ordered()
 */
final class Page extends Model implements ContentOwner, TranslatableModel
{
    use HasContent;
    use HasMetafields;
    use HasSeo;
    use HasUuids;
    use SoftDeletes;
    use Translatable;

    public const string CONTENT_OWNER_TYPE = 'page';

    public const string CONTENT_GROUP = 'content';

    /** @var array<string, mixed> */
    protected $attributes = [
        'parent_key' => '__root__',
        'site' => 'default',
        'kind' => 'static',
        'status' => 'draft',
        'position' => 0,
        'is_navigable' => true,
        'sitemap_included' => true,
        'revision' => 1,
    ];

    /** @var list<string> */
    protected $fillable = [
        'parent_id',
        'key',
        'site',
        'slug',
        'path',
        'kind',
        'resource',
        'status',
        'position',
        'is_navigable',
        'sitemap_included',
        'sitemap_priority',
        'sitemap_change_frequency',
        'published_at',
        'expires_at',
    ];

    /**
     * Return the configured pages table.
     */
    public function getTable(): string
    {
        return PagesConfiguration::table(PagesTables::Pages, PagesTables::Pages);
    }

    /**
     * Return the configured Pages database connection.
     */
    public function getConnectionName(): ?string
    {
        return PagesConfiguration::connection() ?? parent::getConnectionName();
    }

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: PageTranslation::class,
            foreignKey: 'page_id',
            fields: ['title', 'navigation_label', 'summary'],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PageKind::class,
            'status' => PageStatus::class,
            'position' => 'integer',
            'is_navigable' => 'boolean',
            'sitemap_included' => 'boolean',
            'sitemap_priority' => 'decimal:1',
            'sitemap_change_frequency' => SitemapChangeFrequency::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'revision' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Return the structural parent page.
     *
     * @return BelongsTo<Page, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Return child pages in deterministic navigation order.
     *
     * @return HasMany<Page, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    /**
     * Restrict queries to pages that may resolve publicly at the supplied instant.
     *
     * @param  Builder<static>  $query
     */
    public function scopePubliclyVisible(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();
        $query
            ->where(static function (Builder $query) use ($at): void {
                $query
                    ->where(static function (Builder $query) use ($at): void {
                        $query
                            ->where('status', PageStatus::Published)
                            ->where(static function (Builder $query) use ($at): void {
                                $query
                                    ->whereNull('published_at')
                                    ->orWhere('published_at', '<=', $at);
                            });
                    })
                    ->orWhere(static function (Builder $query) use ($at): void {
                        $query
                            ->where('status', PageStatus::Scheduled)
                            ->whereNotNull('published_at')
                            ->where('published_at', '<=', $at);
                    });
            })
            ->where(static function (Builder $query) use ($at): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $at);
            });
    }

    /**
     * Apply deterministic sibling ordering.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('slug')->orderBy('id');
    }

    /**
     * Return the localized display title or an empty string when unavailable.
     */
    public function displayTitle(?string $locale = null): string
    {
        $value = $this->translated('title', $locale);

        return is_string($value) ? $value : '';
    }

    protected static function booted(): void
    {
        self::saving(function (Page $page): void {
            $page->site = trim($page->site);
            $page->slug = PagePath::slug($page->slug);
            $page->path = PagePath::normalize($page->path);
            $page->path_hash = PagePath::hash($page->site, $page->path);
            $page->parent_key = $page->parent_id ?? '__root__';

            if ($page->kind === PageKind::Static && $page->resource !== null) {
                throw new LogicException('Static pages cannot register a dynamic resource handler.');
            }

            if ($page->kind === PageKind::Resource && $page->resource === null) {
                throw new LogicException('Resource pages require a registered handler alias.');
            }

            if ($page->exists && ! $page->isDirty('revision')) {
                $revision = $page->getOriginal('revision');
                $page->revision = is_numeric($revision) ? ((int) $revision) + 1 : 1;
            }
        });
    }
}
