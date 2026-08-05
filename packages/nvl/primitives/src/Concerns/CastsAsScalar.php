<?php

declare(strict_types=1);

namespace Nvl\Primitives\Concerns;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Nvl\Primitives\Casts\ScalarPrimitiveCast;

/**
 * Supplies a reusable Eloquent cast for scalar primitives.
 */
trait CastsAsScalar
{
    /**
     * Return a class-aware scalar cast instance.
     *
     * @param  list<string>  $arguments
     * @return CastsAttributes<static|null, static|string|null>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new ScalarPrimitiveCast(static::class);
    }
}
