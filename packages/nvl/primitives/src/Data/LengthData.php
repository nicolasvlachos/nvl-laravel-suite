<?php

declare(strict_types=1);

namespace Nvl\Primitives\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Primitives\ValueObjects\Length;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable TypeScript representation of an exact canonical length.
 */
#[TypeScript]
final class LengthData extends Data
{
    use DataTransform;

    /**
     * Create the canonical length payload.
     */
    public function __construct(
        public readonly string $value,
        public readonly string $unit,
    ) {}

    /**
     * Create a payload from a length value object.
     */
    public static function fromLength(Length $length): self
    {
        return new self(...$length->toArray());
    }
}
