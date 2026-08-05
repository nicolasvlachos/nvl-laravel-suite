<?php

declare(strict_types=1);

namespace Nvl\Content\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Requires a non-empty PHP array to represent a JSON object rather than a list.
 */
final class ContentObjectRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $fail("The {$attribute} field must be a JSON object.");
        }
    }
}
