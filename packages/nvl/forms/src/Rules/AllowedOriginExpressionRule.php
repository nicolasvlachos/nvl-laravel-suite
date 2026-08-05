<?php

declare(strict_types=1);

namespace Nvl\Forms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Nvl\Forms\Support\AllowedOriginExpression;

/**
 * Validates Forms allowed-origin values as host-only expressions.
 */
final class AllowedOriginExpressionRule implements ValidationRule
{
    /**
     * Validate that the value is a supported allowed-origin host expression.
     *
     * @param  string  $attribute  Attribute under validation
     * @param  mixed  $value  Candidate value
     * @param  Closure(string, string|null=):PotentiallyTranslatedString  $fail  Validation failure callback
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! AllowedOriginExpression::isValid($value)) {
            $fail('forms::forms/validation.custom.allowed_origin_expression.invalid')->translate();
        }
    }
}
