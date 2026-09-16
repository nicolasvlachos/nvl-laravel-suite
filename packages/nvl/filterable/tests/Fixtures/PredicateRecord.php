<?php

declare(strict_types=1);

namespace Nvl\Filterable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a caller-owned row used to prove custom predicate grouping.
 *
 * @property int $id The record identifier.
 * @property string $owner The caller ownership boundary.
 * @property string $name The filterable record name.
 */
final class PredicateRecord extends Model
{
    public $timestamps = false;

    protected $table = 'predicate_records';

    protected $guarded = [];
}
