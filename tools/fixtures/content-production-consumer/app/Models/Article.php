<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Traits\InteractsWithMedia;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Seo\Traits\HasSeo;

/**
 * Application-owned article resource and private-document Media owner.
 *
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property bool $is_published
 *
 * @method static Builder<static> query()
 */
final class Article extends Model implements HasMedia
{
    use HasMetafields;
    use HasSeo;
    use HasUuids;
    use InteractsWithMedia;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['slug', 'title', 'is_published'];

    /** Declare the exact application Media slots. */
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('document')
            ->oneToOne()
            ->acceptsMimeTypes(['application/pdf'])
            ->maxFileSize(2 * 1024 * 1024);

        $this->addMediaSlot('cover')
            ->oneToOne()
            ->acceptsMimeTypes(['image/png'])
            ->maxFileSize(2 * 1024 * 1024);
    }

    /** Resolve dynamic Page resources by their stable public slug. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
