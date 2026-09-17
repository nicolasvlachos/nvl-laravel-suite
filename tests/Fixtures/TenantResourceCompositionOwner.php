<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Traits\InteractsWithMedia;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

/** Consumer-owned model composing every tenant resource trait without Auth. */
final class TenantResourceCompositionOwner extends Model implements HasMedia
{
    use HasMetafields;
    use HasTaxonomies;
    use InteractsWithMedia;
    use SoftDeletes;

    /** @var list<string> */
    protected array $taxonomies = ['category', 'tag'];

    protected $table = 'tenant_resource_composition_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
