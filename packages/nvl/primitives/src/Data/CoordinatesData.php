<?php

declare(strict_types=1);

namespace Nvl\Primitives\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Primitives\ValueObjects\Coordinates;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable API and TypeScript representation of WGS84 coordinates.
 */
#[TypeScript]
final class CoordinatesData extends Data
{
    use DataTransform;

    /**
     * Create a canonical coordinates payload.
     */
    public function __construct(
        public readonly string $latitude,
        public readonly string $longitude,
    ) {}

    /**
     * Create a payload from geographic coordinates.
     */
    public static function fromCoordinates(Coordinates $coordinates): self
    {
        return new self(
            latitude: $coordinates->latitude(),
            longitude: $coordinates->longitude(),
        );
    }
}
