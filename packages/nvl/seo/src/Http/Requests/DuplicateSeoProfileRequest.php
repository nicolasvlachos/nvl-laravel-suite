<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Nvl\Seo\Rules\OwnerIdentifier;

/**
 * Validates duplication of an SEO profile to another owner.
 */
final class DuplicateSeoProfileRequest extends SeoManagementRequest
{
    /**
     * Return validated profile duplication rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ownerAlias' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'ownerId' => ['required', new OwnerIdentifier],
            'scope' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'copyPaths' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Return the target owner alias.
     */
    public function ownerAlias(): string
    {
        return $this->requiredString('ownerAlias');
    }

    /**
     * Return the target owner identifier.
     */
    public function ownerId(): string
    {
        return $this->requiredIdentifier('ownerId');
    }

    /**
     * Return the optional target scope.
     */
    public function scope(): ?string
    {
        return $this->optionalString('scope');
    }

    /**
     * Determine whether locale paths should be copied.
     */
    public function copyPaths(): bool
    {
        return $this->optionalBoolean('copyPaths');
    }
}
