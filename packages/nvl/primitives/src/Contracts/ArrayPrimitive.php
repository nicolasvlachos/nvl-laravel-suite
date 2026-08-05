<?php

declare(strict_types=1);

namespace Nvl\Primitives\Contracts;

/**
 * Defines a primitive persisted as a JSON object in one database column.
 */
interface ArrayPrimitive extends Primitive
{
    /**
     * Reconstruct the primitive from its canonical object representation.
     *
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): static;

    /**
     * Return the canonical object representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
