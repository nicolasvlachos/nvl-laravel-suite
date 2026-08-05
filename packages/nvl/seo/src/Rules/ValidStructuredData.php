<?php

declare(strict_types=1);

namespace Nvl\Seo\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nvl\Seo\Support\StructuredDataLimits;

/**
 * Validates bounded JSON-LD shape without claiming schema.org completeness.
 */
final class ValidStructuredData implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! StructuredDataLimits::accepts($value)) {
            $fail(
                'The :attribute must be a bounded JSON object or list of JSON objects.',
            );
        }
    }
}
