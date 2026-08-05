<?php

declare(strict_types=1);

namespace Nvl\Content\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Localized field values for one content block.
 *
 * @property string $id
 * @property string $content_block_id
 * @property string $locale
 * @property array<string, mixed> $values
 * @property-read ContentBlock $block
 */
final class ContentBlockTranslation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['content_block_id', 'locale', 'values'];

    public function getTable(): string
    {
        return ContentConfiguration::table('blocks_i18n');
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
        return ['values' => 'array'];
    }

    /**
     * Return the canonical content block for this locale row.
     *
     * @return BelongsTo<ContentBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }
}
