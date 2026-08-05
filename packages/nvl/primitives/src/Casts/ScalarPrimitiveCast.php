<?php

declare(strict_types=1);

namespace Nvl\Primitives\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Primitives\Contracts\ScalarPrimitive;

/**
 * Converts one scalar column to and from a specific primitive class.
 *
 * @template TPrimitive of ScalarPrimitive
 *
 * @implements CastsAttributes<TPrimitive|null, TPrimitive|string|null>
 */
final readonly class ScalarPrimitiveCast implements CastsAttributes
{
    /**
     * Create a cast for one scalar primitive class.
     *
     * @param  class-string<TPrimitive>  $primitiveClass
     */
    public function __construct(
        private string $primitiveClass,
    ) {}

    /**
     * Hydrate a primitive from its stored scalar.
     *
     * @param  array<string, mixed>  $attributes
     * @return TPrimitive|null
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?ScalarPrimitive {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Attribute [{$key}] must contain a scalar primitive value.");
        }

        return $this->primitiveClass::from((string) $value);
    }

    /**
     * Convert a primitive or accepted scalar input to storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->primitiveClass) {
            return $value->storageValue();
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Attribute [{$key}] must be a primitive or scalar value.");
        }

        return $this->primitiveClass::from((string) $value)->storageValue();
    }
}
