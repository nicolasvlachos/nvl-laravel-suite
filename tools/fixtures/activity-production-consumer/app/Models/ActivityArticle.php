<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Activity\Contracts\MergesActivity;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Traits\HasModelActivity;
use Nvl\Activity\Traits\MergesActivityTimeline;

/**
 * Consumer-owned model used to prove automatic capture and merged timelines.
 *
 * @property int $id
 * @property string $title
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ActivityArticle extends Model implements MergesActivity
{
    use HasModelActivity;
    use MergesActivityTimeline;

    /** @var string */
    protected $table = 'activity_consumer_articles';

    /** @var list<string> */
    protected $fillable = [
        'title',
        'status',
    ];

    /**
     * @return array<int, iterable<int|string, ActivityItem>>
     */
    protected function mergedActivitySources(?int $limit = null): array
    {
        return [];
    }
}
