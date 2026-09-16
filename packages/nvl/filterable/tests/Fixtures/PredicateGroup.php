<?php

declare(strict_types=1);

namespace Nvl\Filterable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents an owner-bound relation used by predicate preservation tests.
 *
 * @property int $id The group identifier.
 * @property string $owner The related row ownership boundary.
 * @property string $name The filterable group name.
 */
final class PredicateGroup extends Model
{
    public $timestamps = false;

    protected $table = 'predicate_groups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner',
        'name',
    ];
}
