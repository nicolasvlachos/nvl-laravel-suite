<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Traits\HasSeo;

/** Canonical SEO owner used by adopted tenant tests. */
final class TenantSeoOwner extends Model
{
    use HasSeo;
    use HasUuids;

    protected $table = 'seo_tenant_test_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
