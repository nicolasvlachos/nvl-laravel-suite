<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Nvl\Primitives\Concerns\CastsAsArray;
use Nvl\Primitives\Contracts\ArrayPrimitive;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Immutable international postal address without presentation assumptions.
 */
final readonly class PostalAddress implements ArrayPrimitive
{
    use CastsAsArray;

    public string $line1;

    public ?string $line2;

    public string $locality;

    public ?string $administrativeArea;

    public ?string $postalCode;

    public CountryCode $country;

    /**
     * Create and normalize an international postal address.
     */
    public function __construct(
        string $line1,
        ?string $line2,
        string $locality,
        ?string $administrativeArea,
        ?string $postalCode,
        CountryCode $country,
    ) {
        $line1 = trim($line1);
        $line2 = $line2 !== null && trim($line2) !== '' ? trim($line2) : null;
        $locality = trim($locality);
        $administrativeArea = $administrativeArea !== null
            && trim($administrativeArea) !== ''
                ? trim($administrativeArea)
                : null;
        $postalCode = $postalCode !== null && trim($postalCode) !== ''
            ? trim($postalCode)
            : null;

        if ($line1 === '' || $locality === '') {
            throw InvalidPrimitive::for(
                'postal address',
                'line1 and locality cannot be empty.',
            );
        }

        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->locality = $locality;
        $this->administrativeArea = $administrativeArea;
        $this->postalCode = $postalCode;
        $this->country = $country;
    }

    /**
     * Create an address from its structured representation.
     *
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): static
    {
        $line1 = $value['line1'] ?? null;
        $locality = $value['locality'] ?? null;
        $postalCode = $value['postalCode'] ?? $value['postal_code'] ?? null;
        $country = $value['country'] ?? null;

        if (! is_string($line1) || ! is_string($locality) || ! is_string($country)) {
            throw InvalidPrimitive::for(
                'postal address',
                'line1, locality, and country are required strings.',
            );
        }

        $line2 = $value['line2'] ?? null;
        $administrativeArea = $value['administrativeArea']
            ?? $value['administrative_area']
            ?? null;

        if (
            ($line2 !== null && ! is_string($line2))
            || ($administrativeArea !== null && ! is_string($administrativeArea))
            || ($postalCode !== null && ! is_string($postalCode))
        ) {
            throw InvalidPrimitive::for(
                'postal address',
                'line2, administrativeArea, and postalCode must be strings or null.',
            );
        }

        return new self(
            line1: trim($line1),
            line2: $line2,
            locality: trim($locality),
            administrativeArea: $administrativeArea,
            postalCode: $postalCode,
            country: CountryCode::from($country),
        );
    }

    /**
     * Return the canonical structured representation.
     *
     * @return array{
     *     line1: string,
     *     line2: string|null,
     *     locality: string,
     *     administrativeArea: string|null,
     *     postalCode: string|null,
     *     country: string
     * }
     */
    public function toArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'locality' => $this->locality,
            'administrativeArea' => $this->administrativeArea,
            'postalCode' => $this->postalCode,
            'country' => (string) $this->country,
        ];
    }

    /**
     * Determine whether another primitive contains the same address fields.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->toArray() === $this->toArray();
    }

    /**
     * Return the canonical JSON representation.
     *
     * @return array{
     *     line1: string,
     *     line2: string|null,
     *     locality: string,
     *     administrativeArea: string|null,
     *     postalCode: string|null,
     *     country: string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Return a compact comma-separated address representation.
     */
    public function __toString(): string
    {
        $locality = $this->postalCode !== null
            ? "{$this->postalCode} {$this->locality}"
            : $this->locality;

        return implode(', ', array_filter([
            $this->line1,
            $this->line2,
            $locality,
            $this->administrativeArea,
            (string) $this->country,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
