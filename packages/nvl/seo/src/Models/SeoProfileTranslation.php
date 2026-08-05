<?php

declare(strict_types=1);

namespace Nvl\Seo\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Enums\TwitterCard;
use Nvl\Seo\Support\SeoPath;

/**
 * Locale-specific SEO metadata for one profile.
 *
 * @property string $id
 * @property string $seo_profile_id
 * @property string $scope
 * @property string $locale
 * @property string|null $path
 * @property string|null $path_hash
 * @property string|null $title
 * @property string|null $description
 * @property string|null $canonical_url
 * @property string|null $image_url
 * @property string|null $image_reference
 * @property string|null $image_alt
 * @property string|null $open_graph_title
 * @property string|null $open_graph_description
 * @property string|null $twitter_title
 * @property string|null $twitter_description
 * @property TwitterCard|null $twitter_card
 * @property array<array-key, mixed>|null $structured_data
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SeoProfile $profile
 */
final class SeoProfileTranslation extends Model
{
    use HasUuids;

    protected $table = SeoTables::I18n;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'locale',
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
    ];

    /**
     * Define the translation owner.
     *
     * @return BelongsTo<SeoProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SeoProfile::class, 'seo_profile_id');
    }

    /**
     * Keep route normalization and the database uniqueness key inseparable.
     */
    protected static function booted(): void
    {
        self::saving(function (SeoProfileTranslation $translation): void {
            $profile = $translation->profile()->firstOrFail();
            $translation->scope = $profile->scope;
            $translation->path = SeoPath::normalize($translation->path);
            $translation->path_hash = SeoPath::hash(
                $profile->scope,
                $translation->locale,
                $translation->path,
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'twitter_card' => TwitterCard::class,
            'structured_data' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
