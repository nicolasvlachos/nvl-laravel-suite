<?php

declare(strict_types=1);

namespace Nvl\Content\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Immutable audit snapshot created by public block mutations.
 *
 * @property string $id
 * @property string $content_block_id
 * @property int $revision
 * @property ContentRevisionEvent $event
 * @property array<string, mixed> $snapshot
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property-read ContentBlock $block
 */
final class ContentRevision extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'content_block_id',
        'revision',
        'event',
        'snapshot',
        'actor_type',
        'actor_id',
    ];

    public function getTable(): string
    {
        return ContentConfiguration::table('revisions');
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
            'revision' => 'integer',
            'event' => ContentRevisionEvent::class,
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ContentBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }
}
