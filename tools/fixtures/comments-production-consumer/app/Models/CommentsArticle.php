<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

/**
 * Represents a consumer-owned string-key article that accepts comment threads.
 *
 * @property string $id
 * @property string $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CommentsArticle extends Model implements HasComments
{
    use InteractsWithComments;

    public const string TABLE = 'comments_consumer_articles';

    /** @var string */
    protected $table = self::TABLE;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'title',
    ];
}
