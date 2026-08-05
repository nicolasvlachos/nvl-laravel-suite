<?php

declare(strict_types=1);

namespace App\Auth\ApiTokens;

use Nvl\Auth\Contracts\ApiTokenAbilityProvider;
use Nvl\Auth\ValueObjects\ApiTokenAbilityDefinition;

/**
 * Declares the consumer-owned abilities grantable to managed API tokens.
 */
final readonly class ApplicationApiTokenAbilityProvider implements ApiTokenAbilityProvider
{
    /**
     * Return the complete deterministic ability catalog.
     *
     * @return list<ApiTokenAbilityDefinition>
     */
    public function abilities(): array
    {
        return [
            new ApiTokenAbilityDefinition(
                name: 'profile:read',
                group: 'Profile',
                description: 'Read the authenticated user profile.',
            ),
            new ApiTokenAbilityDefinition(
                name: 'profile:update',
                group: 'Profile',
                description: 'Update the authenticated user profile.',
            ),
        ];
    }
}
