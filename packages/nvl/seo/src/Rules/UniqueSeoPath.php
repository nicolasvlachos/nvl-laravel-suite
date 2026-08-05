<?php

declare(strict_types=1);

namespace Nvl\Seo\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoScope;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Provides an early validation error for the database-enforced route invariant.
 */
final readonly class UniqueSeoPath implements ValidationRule
{
    public function __construct(
        private string $locale,
        private ?string $scope = null,
        private ?string $ignoreProfileId = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $scope = SeoScope::normalize($this->scope);
        $locale = (new LocaleCode($this->locale))->value;
        $hash = SeoPath::hash($scope, $locale, $value);

        if ($hash === null) {
            return;
        }

        $exists = SeoProfileTranslation::query()
            ->where('scope', $scope)
            ->where('locale', $locale)
            ->where('path_hash', $hash)
            ->when(
                $this->ignoreProfileId !== null,
                fn ($query) => $query->where('seo_profile_id', '!=', $this->ignoreProfileId),
            )
            ->exists();

        if ($exists) {
            $fail('The :attribute is already used by another SEO profile in this locale.');
        }
    }
}
