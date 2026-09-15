<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;

/**
 * Consumer owner that preserves placements on reversible deletion.
 *
 * @property string $id
 */
final class TestSoftDeletingContentOwner extends Model implements ContentOwner
{
    use HasContent;
    use HasUuids;
    use SoftDeletes;

    public const string CONTENT_GROUP = 'default';

    protected $table = 'content_test_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
