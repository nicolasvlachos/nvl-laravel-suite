<?php

declare(strict_types=1);

namespace Nvl\Primitives\Contracts;

/**
 * Defines a primitive persisted as one scalar database column.
 */
interface ScalarPrimitive extends Primitive
{
    /**
     * Reconstruct the primitive from its canonical storage representation.
     */
    public static function from(string $value): static;

    /**
     * Return the canonical scalar representation used for persistence.
     */
    public function storageValue(): string;
}
