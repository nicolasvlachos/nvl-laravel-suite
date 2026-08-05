<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumer-owned resource fixture used to prove standalone dynamic resolution.
 *
 * @property string $id
 * @property string $name
 * @property bool $is_public
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TestPageResource extends Model
{
    use HasUuids;

    protected $table = 'page_test_resources';

    /** @var list<string> */
    protected $fillable = ['name', 'is_public'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
