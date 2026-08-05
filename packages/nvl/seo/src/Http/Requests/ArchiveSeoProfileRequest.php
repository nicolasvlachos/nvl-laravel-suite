<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

/**
 * Validates an optimistic archive or restore mutation.
 */
final class ArchiveSeoProfileRequest extends SeoManagementRequest
{
    /**
     * Return validated archive mutation rules.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'archived' => ['required', 'boolean'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Determine whether the profile should be archived.
     */
    public function archived(): bool
    {
        return $this->requiredBoolean('archived');
    }

    /**
     * Return the required optimistic revision.
     */
    public function expectedRevision(): int
    {
        return $this->requiredInteger('expectedRevision');
    }
}
