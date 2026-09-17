<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

/** Standalone Taxonomy owner with no Auth or Media dependency. */
final class TenantTaxonomyRecord extends Model
{
    use HasTaxonomies;
    use HasUuids;

    /** @var string */
    protected $table = 'tenant_articles';

    /** @var list<string> */
    protected $fillable = ['slug', 'title'];

}
