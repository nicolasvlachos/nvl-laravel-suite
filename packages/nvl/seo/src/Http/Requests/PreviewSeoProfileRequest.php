<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validates an optional locale for resolved SEO preview.
 */
final class PreviewSeoProfileRequest extends SeoManagementRequest
{
    /**
     * Return validated preview rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $configured = config('translatable.locales', ['en']);
        $locales = is_array($configured)
            ? array_values(array_filter($configured, 'is_string'))
            : ['en'];

        return [
            'locale' => ['nullable', 'string', 'max:35', Rule::in($locales)],
        ];
    }

    /**
     * Return the optional preview locale.
     */
    public function locale(): ?string
    {
        return $this->optionalString('locale');
    }
}
