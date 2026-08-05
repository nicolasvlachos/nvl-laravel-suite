<?php

declare(strict_types=1);

namespace Nvl\Seo\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use Nvl\Seo\Support\SeoModelIdentifier;

/**
 * Accepts string and integer Eloquent keys at management boundaries.
 */
final class OwnerIdentifier implements ValidationRule
{
    /**
     * Validate one database-compatible owner identifier.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute field must be a string or integer identifier.');

            return;
        }

        try {
            SeoModelIdentifier::normalize($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
