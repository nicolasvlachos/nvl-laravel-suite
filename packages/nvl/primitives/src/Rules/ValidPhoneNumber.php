<?php

declare(strict_types=1);

namespace Nvl\Primitives\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\ValueObjects\PhoneNumber;

/**
 * Validates an international or region-specific telephone number.
 */
final readonly class ValidPhoneNumber implements ValidationRule
{
    /**
     * Create a phone rule with an optional explicit parsing region.
     */
    public function __construct(
        private ?string $region = null,
    ) {}

    /**
     * Validate a telephone number through the maintained numbering registry.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('primitives::validation.invalid_phone_number')->translate();

            return;
        }

        try {
            $this->region === null
                ? PhoneNumber::from($value)
                : PhoneNumber::fromRegion($value, $this->region);
        } catch (InvalidPrimitive) {
            $fail('primitives::validation.invalid_phone_number')->translate();
        }
    }
}
