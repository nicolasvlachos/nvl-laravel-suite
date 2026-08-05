<?php

declare(strict_types=1);

namespace Nvl\Primitives\Concerns;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Nvl\Primitives\Casts\ArrayPrimitiveCast;

/**
 * Supplies a reusable Eloquent JSON cast for object-shaped primitives.
 */
trait CastsAsArray
{
    /**
     * Return a class-aware JSON cast instance.
     *
     * @param  list<string>  $arguments
     * @return ArrayPrimitiveCast<static>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new ArrayPrimitiveCast(static::class);
    }
}
