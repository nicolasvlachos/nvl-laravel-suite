<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Application resource queried through the tenant-safe Page handler boundary.
 *
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property bool $is_public
 */
final class TenantPageResource extends Model
{
    use HasUuids;

    protected $table = 'page_tenant_test_resources';

    /** @var list<string> */
    protected $fillable = ['slug', 'title', 'is_public'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
