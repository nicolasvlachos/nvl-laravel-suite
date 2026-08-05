<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Traits\HasSeo;

/**
 * Minimal owner used to exercise the package's polymorphic integration.
 */
final class TestSeoOwner extends Model
{
    use HasSeo;
    use HasUuids;

    protected $table = 'seo_test_owners';

    /**
     * @var list<string>
     */
    protected $fillable = ['name'];
}
