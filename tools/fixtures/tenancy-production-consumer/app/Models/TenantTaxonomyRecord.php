<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Taxonomy\Concerns\HasTaxonomies;

/**
 * Standalone Taxonomy owner with no Auth or Media dependency.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $slug
 * @property string $title
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> query()
 */
final class TenantTaxonomyRecord extends Model
{
    use HasTaxonomies;
    use HasUuids;

    /** @var string */
    protected $table = 'tenant_articles';

    /** @var list<string> */
    protected $fillable = ['slug', 'title'];
}
