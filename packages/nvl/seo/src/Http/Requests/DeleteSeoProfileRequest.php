<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

/**
 * Validates an optimistic SEO profile deletion.
 */
final class DeleteSeoProfileRequest extends SeoManagementRequest
{
    /**
     * Return validated profile deletion rules.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Return the required optimistic revision.
     */
    public function expectedRevision(): int
    {
        return $this->requiredInteger('expectedRevision');
    }
}
