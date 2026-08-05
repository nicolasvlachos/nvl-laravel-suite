<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Illuminate\Validation\Rule;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Rules\OwnerIdentifier;

/**
 * Validates creation of one registered owner's SEO profile.
 */
final class StoreSeoProfileRequest extends SeoManagementRequest
{
    /**
     * Return validated profile creation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ownerAlias' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'ownerId' => ['required', new OwnerIdentifier],
            'scope' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'profile' => [
                'required',
                'array:'.implode(',', SeoProfilePayload::fields()),
            ],
            ...SeoProfilePayload::scopedRules('profile.'),
            'profile.expectedRevision' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::in([0]),
            ],
        ];
    }

    /**
     * Return the registered owner alias.
     */
    public function ownerAlias(): string
    {
        return $this->requiredString('ownerAlias');
    }

    /**
     * Return the registered owner identifier.
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
     * Build the validated profile payload with create-only revision semantics.
     */
    public function payload(): SeoProfilePayload
    {
        $profile = $this->requiredArray('profile');
        $profile['expectedRevision'] = 0;

        return SeoProfilePayload::from($profile);
    }
}
