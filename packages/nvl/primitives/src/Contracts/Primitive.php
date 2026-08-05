<?php

declare(strict_types=1);

namespace Nvl\Primitives\Contracts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use JsonSerializable;
use Stringable;

/**
 * Marks an immutable, validated application value object.
 */
interface Primitive extends Castable, JsonSerializable, Stringable
{
    /**
     * Compare value and type without coercion.
     */
    public function equals(Primitive $other): bool;
}
