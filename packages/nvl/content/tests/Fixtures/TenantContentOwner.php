<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;

/** Canonical tenant-owned Content composition fixture. */
final class TenantContentOwner extends Model implements ContentOwner
{
    use HasContent;
    use HasUuids;

    public const array CONTENT_GROUPS = ['default'];

    protected $table = 'content_tenant_test_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
