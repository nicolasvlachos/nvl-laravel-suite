<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;

/**
 * Consumer model used to prove string-compatible owner placement.
 */
final class TestContentOwner extends Model implements ContentOwner
{
    use HasContent;
    use HasUuids;

    public const array CONTENT_GROUPS = [
        'default',
        'homepage',
        'main',
        'primary',
        'secondary',
    ];

    protected $table = 'content_test_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
