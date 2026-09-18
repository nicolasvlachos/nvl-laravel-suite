<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Traits\InteractsWithMedia;

/**
 * Fixture-owned Media parent whose canonical identity belongs to one tenant.
 *
 * @property string $id Stable article UUID.
 * @property string|null $tenant_id Canonical tenant owner after adoption.
 * @property string $slug Tenant-local business key.
 * @property string $title Fixture display title.
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> query()
 */
final class TenantArticle extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;

    /** @var list<string> */
    protected $fillable = ['slug', 'title'];

    /** Declare the exact fixture Media slot. */
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('document')
            ->oneToOne()
            ->acceptsMimeTypes(['text/plain'])
            ->maxFileSize(1024 * 1024);
    }
}
