<?php

declare(strict_types=1);

namespace Nvl\Primitives\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Primitives\ValueObjects\PostalAddress;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable API and TypeScript representation of an international postal address.
 */
#[TypeScript]
final class PostalAddressData extends Data
{
    use DataTransform;

    /**
     * Create an API postal-address payload.
     */
    public function __construct(
        public readonly string $line1,
        public readonly ?string $line2,
        public readonly string $locality,
        public readonly ?string $administrativeArea,
        public readonly ?string $postalCode,
        public readonly string $country,
    ) {}

    /**
     * Create an API payload from a postal address.
     */
    public static function fromAddress(PostalAddress $address): self
    {
        return new self(
            line1: $address->line1,
            line2: $address->line2,
            locality: $address->locality,
            administrativeArea: $address->administrativeArea,
            postalCode: $address->postalCode,
            country: (string) $address->country,
        );
    }
}
