<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;

/**
 * Consumer model used to prove integer owner compatibility with text morph columns.
 */
final class TestIntegerContentOwner extends Model implements ContentOwner
{
    use HasContent;

    public const array CONTENT_GROUPS = ['default'];

    protected $table = 'content_integer_test_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
