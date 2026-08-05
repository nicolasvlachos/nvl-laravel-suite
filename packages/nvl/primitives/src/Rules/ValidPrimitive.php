<?php

declare(strict_types=1);

namespace Nvl\Primitives\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Validates input by constructing the requested scalar primitive.
 */
final readonly class ValidPrimitive implements ValidationRule
{
    /**
     * Create a validation rule for one scalar primitive class.
     *
     * @param  class-string  $primitiveClass
     */
    public function __construct(
        private string $primitiveClass,
        private string $label = 'value',
    ) {
        if (! is_a($this->primitiveClass, ScalarPrimitive::class, true)) {
            throw new InvalidArgumentException(
                "Primitive validation class [{$this->primitiveClass}] must implement ScalarPrimitive.",
            );
        }
    }

    /**
     * Validate a scalar value through its canonical primitive.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value)) {
            $fail('primitives::validation.invalid_primitive')->translate([
                'primitive' => $this->label,
            ]);

            return;
        }

        try {
            $this->primitiveClass::from((string) $value);
        } catch (InvalidPrimitive) {
            $fail('primitives::validation.invalid_primitive')->translate([
                'primitive' => $this->label,
            ]);
        }
    }
}
