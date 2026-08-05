<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Nvl\Seo\Data\SeoProfileQuery;

/**
 * Validates the allowlisted SEO profile management filters.
 */
final class ListSeoProfilesRequest extends SeoManagementRequest
{
    /**
     * Return validated profile list rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SeoProfileQuery::rules();
    }

    /**
     * Build the transport-neutral profile query.
     */
    public function profileQuery(): SeoProfileQuery
    {
        return SeoProfileQuery::from($this->validated());
    }
}
