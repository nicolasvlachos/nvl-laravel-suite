<?php

declare(strict_types=1);

namespace Nvl\Content\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Ordered placement of a reusable block within an allowlisted owner.
 *
 * @property string $id
 * @property string $content_block_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $group
 * @property string $key
 * @property string|null $parent_id
 * @property string $region
 * @property int $sort_order
 * @property bool $is_visible
 * @property array<string, mixed>|null $overrides
 * @property int $revision
 * @property-read ContentBlock $block
 * @property-read Model $owner
 * @property-read ContentPlacement|null $parent
 * @property-read Collection<int, ContentPlacement> $children
 */
final class ContentPlacement extends Model
{
    use HasUuids;

    public const string DEFAULT_GROUP = 'default';

    /** @var list<string> */
    protected $fillable = [
        'content_block_id',
        'owner_type',
        'owner_id',
        'group',
        'key',
        'parent_id',
        'region',
        'sort_order',
        'is_visible',
        'overrides',
        'revision',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'group' => self::DEFAULT_GROUP,
        'region' => 'main',
        'sort_order' => 0,
        'is_visible' => true,
        'revision' => 1,
    ];

    public function getTable(): string
    {
        return ContentConfiguration::table('placements');
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
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'overrides' => 'array',
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ContentBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }

    /**
     * Return the registered model that owns this grouped placement.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /**
     * @return BelongsTo<ContentPlacement, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ContentPlacement, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
