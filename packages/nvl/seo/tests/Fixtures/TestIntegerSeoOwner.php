<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Integer-keyed owner used to verify management boundary compatibility.
 */
final class TestIntegerSeoOwner extends Model
{
    protected $table = 'seo_test_integer_owners';

    /**
     * @var list<string>
     */
    protected $fillable = ['name'];
}
