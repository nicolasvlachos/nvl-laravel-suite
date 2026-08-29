<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;

/**
 * Consumer model used to prove arbitrary string owner compatibility.
 */
final class TestStringContentOwner extends Model implements ContentOwner
{
    use HasContent;

    public const array CONTENT_GROUPS = ['default'];

    public $incrementing = false;

    protected $table = 'content_string_test_owners';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'name'];
}
