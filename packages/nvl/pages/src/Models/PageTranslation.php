<?php

declare(strict_types=1);

namespace Nvl\Pages\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Localized editorial copy for one page.
 *
 * @property string $id
 * @property string $page_id
 * @property string $locale
 * @property string $title
 * @property string|null $navigation_label
 * @property string|null $summary
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Page $page
 */
final class PageTranslation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'page_id',
        'locale',
        'title',
        'navigation_label',
        'summary',
    ];

    /**
     * Return the configured page translation table.
     */
    public function getTable(): string
    {
        return PagesConfiguration::table('pages_i18n', 'pages_i18n');
    }

    /**
     * Return the configured Pages database connection.
     */
    public function getConnectionName(): ?string
    {
        return PagesConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
