<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

/**
 * Validates the optional scope for SEO profile status totals.
 */
final class SeoProfileStatusRequest extends SeoManagementRequest
{
    /**
     * Return validated status-query rules.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
        ];
    }

    /**
     * Return the optional status scope.
     */
    public function scope(): ?string
    {
        return $this->optionalString('scope');
    }
}
