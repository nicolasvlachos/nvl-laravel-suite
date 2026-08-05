<?php

declare(strict_types=1);

namespace Nvl\Primitives\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use Nvl\Primitives\Contracts\ArrayPrimitive;

/**
 * Converts one JSON column to and from a specific object-shaped primitive.
 *
 * @template TPrimitive of ArrayPrimitive
 *
 * @implements CastsAttributes<TPrimitive|null, TPrimitive|array<string, mixed>|string|null>
 */
final readonly class ArrayPrimitiveCast implements CastsAttributes
{
    /**
     * Create a cast for one structured primitive class.
     *
     * @param  class-string<TPrimitive>  $primitiveClass
     */
    public function __construct(
        private string $primitiveClass,
    ) {}

    /**
     * Hydrate a primitive from its stored JSON object.
     *
     * @param  array<string, mixed>  $attributes
     * @return TPrimitive|null
     *
     * @throws JsonException
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?ArrayPrimitive {
        if ($value === null) {
            return null;
        }

        $payload = is_string($value)
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : $value;

        if (! is_array($payload)) {
            throw new InvalidArgumentException("Attribute [{$key}] must contain a JSON object.");
        }

        /** @var array<string, mixed> $payload */
        return $this->primitiveClass::fromArray($payload);
    }

    /**
     * Convert a primitive or structured input to canonical JSON.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
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
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        }

        if (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Attribute [{$key}] must be a primitive or JSON object.");
        }

        /** @var array<string, mixed> $value */
        return json_encode(
            $this->primitiveClass::fromArray($value)->toArray(),
            JSON_THROW_ON_ERROR,
        );
    }
}
