<?php

declare(strict_types=1);

namespace Nvl\Seo\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Nvl\Seo\Support\HttpUrl;

/**
 * Validates fragment-free canonical HTTP and HTTPS URLs.
 */
final readonly class CanonicalUrl implements ValidationRule
{
    /**
     * Validate one canonical URL.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! HttpUrl::isCanonical($value)) {
            $fail("The {$attribute} field must be an absolute HTTP(S) URL without credentials or a fragment.");
        }
    }
}
