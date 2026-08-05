<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Nvl\Seo\Data\Mutations\SeoProfilePayload;

/**
 * Validates an optimistic SEO profile update.
 */
final class UpdateSeoProfileRequest extends SeoManagementRequest
{
    /**
     * Return validated profile update rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace(
            SeoProfilePayload::rules(),
            ['expectedRevision' => ['required', 'integer', 'min:1']],
        );
    }

    /**
     * Build the validated profile mutation.
     */
    public function payload(): SeoProfilePayload
    {
        return SeoProfilePayload::from($this->validated());
    }
}
