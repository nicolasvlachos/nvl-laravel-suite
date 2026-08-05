<?php

declare(strict_types=1);

namespace Nvl\Filterable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal related model used to verify allowlisted relation filtering.
 */
final class FilterableGroup extends Model
{
    public $timestamps = false;

    protected $table = 'filterable_groups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
