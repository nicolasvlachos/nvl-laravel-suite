<?php

declare(strict_types=1);

namespace Nvl\Filterable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an owner-bound row used to verify relationship predicates.
 *
 * @property int $id The record identifier.
 * @property string $owner The caller ownership boundary.
 * @property string $name The filterable record name.
 * @property int $group_id The related group identifier.
 */
final class RelatedPredicateRecord extends Model
{
    public $timestamps = false;

    protected $table = 'related_predicate_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner',
        'name',
        'group_id',
    ];

    /**
     * Return the group only when the related row shares this record's owner.
     *
     * @return BelongsTo<PredicateGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PredicateGroup::class, 'group_id')
            ->whereColumn('predicate_groups.owner', 'related_predicate_records.owner');
    }
}
