<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Nvl\Primitives\Concerns\CastsAsArray;
use Nvl\Primitives\Contracts\ArrayPrimitive;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\Support\BrickMathCompatibility;

/**
 * Immutable WGS84 latitude/longitude pair.
 */
final readonly class Coordinates implements ArrayPrimitive
{
    use CastsAsArray;

    private const float EARTH_RADIUS_KILOMETRES = 6371.0088;

    private function __construct(
        private BigDecimal $latitude,
        private BigDecimal $longitude,
    ) {}

    public static function from(string|int|float $latitude, string|int|float $longitude): self
    {
        try {
            $latitude = BigDecimal::of((string) $latitude);
            $longitude = BigDecimal::of((string) $longitude);
        } catch (MathException $exception) {
            throw InvalidPrimitive::for('coordinates', $exception->getMessage());
        }

        if ($latitude->isLessThan(-90) || $latitude->isGreaterThan(90)) {
            throw InvalidPrimitive::for('coordinates', 'latitude must be between -90 and 90.');
        }

        if ($longitude->isLessThan(-180) || $longitude->isGreaterThan(180)) {
            throw InvalidPrimitive::for('coordinates', 'longitude must be between -180 and 180.');
        }

        return new self(
            $latitude->toScale(7, BrickMathCompatibility::halfUp()),
            $longitude->toScale(7, BrickMathCompatibility::halfUp()),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): static
    {
        $latitude = $value['latitude'] ?? $value['lat'] ?? null;
        $longitude = $value['longitude'] ?? $value['lng'] ?? null;

        if (! is_scalar($latitude) || ! is_scalar($longitude)) {
            throw InvalidPrimitive::for('coordinates', 'latitude and longitude are required.');
        }

        return self::from((string) $latitude, (string) $longitude);
    }

    public static function fromString(string $value): self
    {
        $parts = array_map(trim(...), explode(',', $value));

        if (count($parts) !== 2) {
            throw InvalidPrimitive::for('coordinates', 'expected a "latitude,longitude" pair.');
        }

        return self::from($parts[0], $parts[1]);
    }

    public function latitude(): string
    {
        return (string) $this->latitude;
    }

    public function longitude(): string
    {
        return (string) $this->longitude;
    }

    public function distanceTo(self $other): float
    {
        $latitude = deg2rad((float) $this->latitude());
        $otherLatitude = deg2rad((float) $other->latitude());
        $latitudeDelta = deg2rad((float) $other->latitude() - (float) $this->latitude());
        $longitudeDelta = deg2rad((float) $other->longitude() - (float) $this->longitude());
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($latitude) * cos($otherLatitude) * sin($longitudeDelta / 2) ** 2;
        $haversine = max(0.0, min(1.0, $haversine));

        return self::EARTH_RADIUS_KILOMETRES * 2 * atan2(
            sqrt($haversine),
            sqrt(1 - $haversine),
        );
    }

    public function googleMapsUrl(): Url
    {
        return Url::from('https://www.google.com/maps?q='.$this->latitude().','.$this->longitude());
    }

    /**
     * @return array{latitude: string, longitude: string}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude(),
            'longitude' => $this->longitude(),
        ];
    }

    public function equals(Primitive $other): bool
    {
        return $other instanceof self
            && $other->latitude->isEqualTo($this->latitude)
            && $other->longitude->isEqualTo($this->longitude);
    }

    /**
     * @return array{latitude: string, longitude: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->latitude().','.$this->longitude();
    }
}
