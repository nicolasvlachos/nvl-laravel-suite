<?php

declare(strict_types=1);

namespace Nvl\Filterable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents an owner-bound row with a nullable predicate value.
 *
 * @property int $id The record identifier.
 * @property string $owner The caller ownership boundary.
 * @property string|null $name The nullable filterable record name.
 */
final class NullablePredicateRecord extends Model
{
    public $timestamps = false;

    protected $table = 'nullable_predicate_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner',
        'name',
    ];
}
