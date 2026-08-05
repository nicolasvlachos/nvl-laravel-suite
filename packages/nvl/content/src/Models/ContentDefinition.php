<?php

declare(strict_types=1);

namespace Nvl\Content\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nvl\Content\Casts\ContentSchemaCast;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Queryable mirror of one source-controlled content definition.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property int $version
 * @property string|null $view
 * @property ContentSchema $schema
 * @property array<string, mixed>|null $defaults
 * @property list<string>|null $allowed_scopes
 * @property list<string>|null $allowed_regions
 * @property bool $is_active
 * @property int $sort_order
 * @property string $source_hash
 * @property Carbon|null $synced_at
 * @property Carbon|null $orphaned_at
 * @property-read Collection<int, ContentBlock> $blocks
 */
final class ContentDefinition extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'version',
        'view',
        'schema',
        'defaults',
        'allowed_scopes',
        'allowed_regions',
        'is_active',
        'sort_order',
        'source_hash',
        'synced_at',
        'orphaned_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'content',
        'version' => 1,
        'is_active' => true,
        'sort_order' => 0,
    ];

    public function getTable(): string
    {
        return ContentConfiguration::table('definitions');
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
            'version' => 'integer',
            'schema' => ContentSchemaCast::class,
            'defaults' => 'array',
            'allowed_scopes' => 'array',
            'allowed_regions' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'synced_at' => 'immutable_datetime',
            'orphaned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ContentBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class, 'definition_id');
    }
}
