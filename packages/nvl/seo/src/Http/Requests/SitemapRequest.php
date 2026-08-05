<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nvl\Seo\Support\SeoScope;

/**
 * Validates the optional public sitemap scope selector.
 */
final class SitemapRequest extends FormRequest
{
    /**
     * Public sitemap discovery does not require application authorization.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return the sitemap query validation contract.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::in(SeoScope::publicSitemapScopes()),
            ],
        ];
    }

    /**
     * Return the optional requested sitemap scope.
     */
    public function scope(): ?string
    {
        $scope = $this->validated('scope');

        return is_string($scope) && $scope !== '' ? $scope : null;
    }
}
