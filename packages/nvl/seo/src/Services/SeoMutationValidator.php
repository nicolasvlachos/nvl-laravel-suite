<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Support\HttpUrl;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoRedirectTarget;
use Nvl\Translatable\Services\LocaleRegistry;
use Spatie\LaravelData\Optional;

/**
 * Enforces transport-independent validation for typed SEO mutations.
 */
final readonly class SeoMutationValidator
{
    /**
     * Create the mutation invariant validator.
     */
    public function __construct(
        private Factory $validation,
        private LocaleRegistry $locales,
    ) {}

    /**
     * Validate one complete profile mutation before persistence.
     */
    public function profile(SeoProfilePayload $payload): void
    {
        $this->validate($payload->toArray(), SeoProfilePayload::rules());

        if ($payload->translations instanceof Optional) {
            return;
        }

        foreach ($payload->translations as $locale => $translation) {
            $this->assertTranslationSemantics($locale, $translation);
        }
    }

    /**
     * Validate one redirect mutation before persistence.
     */
    public function redirect(SeoRedirectPayload $payload): void
    {
        $this->validate($payload->toArray(), SeoRedirectPayload::rules());

        if ($payload->locale !== null && ! $this->locales->supports($payload->locale)) {
            throw InvalidSeoMutationException::forField(
                'locale',
                "The locale [{$payload->locale}] is not supported.",
            );
        }

        try {
            SeoPath::normalize($payload->sourcePath);
            SeoRedirectTarget::normalize($payload->target);
        } catch (InvalidArgumentException $exception) {
            throw InvalidSeoMutationException::because(
                $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Validate a field map through the canonical DTO rules.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $rules
     */
    private function validate(array $values, array $rules): void
    {
        try {
            $this->validation->make($values, $rules)->validate();
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                if (! is_string($field) || ! is_array($messages)) {
                    continue;
                }

                $normalizedMessages = [];

                foreach ($messages as $message) {
                    if (is_string($message)) {
                        $normalizedMessages[] = $message;
                    }
                }

                $errors[$field] = $normalizedMessages;
            }

            throw InvalidSeoMutationException::because(
                'The SEO mutation payload is invalid.',
                $errors,
                $exception,
            );
        }
    }

    /**
     * Validate path and URL semantics that Laravel's primitive rules cannot express.
     *
     * @param  array<string, mixed>  $translation
     */
    private function assertTranslationSemantics(string $locale, array $translation): void
    {
        if (! $this->locales->supports($locale)) {
            throw InvalidSeoMutationException::forField(
                "translations.{$locale}",
                "The locale [{$locale}] is not supported.",
            );
        }

        try {
            $path = $translation['path'] ?? null;

            if (is_string($path)) {
                SeoPath::normalize($path);
            }
        } catch (InvalidArgumentException $exception) {
            throw InvalidSeoMutationException::forField(
                "translations.{$locale}.path",
                $exception->getMessage(),
                $exception,
            );
        }

        $canonical = $translation['canonicalUrl']
            ?? $translation['canonical_url']
            ?? null;

        if (is_string($canonical) && ! HttpUrl::isCanonical($canonical)) {
            throw InvalidSeoMutationException::forField(
                "translations.{$locale}.canonicalUrl",
                'A canonical URL must be an absolute HTTP(S) URL without credentials or a fragment.',
            );
        }
    }
}
