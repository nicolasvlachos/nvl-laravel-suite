<?php

declare(strict_types=1);

namespace App\Auth\Social;

use Nvl\Auth\Contracts\SocialIdentityAcquirer;
use Nvl\Auth\ValueObjects\AcquiredSocialIdentity;
use Nvl\Auth\ValueObjects\SecretValue;
use Nvl\Auth\ValueObjects\SocialAuthorizationRequest;
use Nvl\Auth\ValueObjects\SocialCallbackRequest;
use Nvl\Auth\ValueObjects\SocialProviderDefinition;
use RuntimeException;

/**
 * Emulates provider readiness without pretending to exchange production credentials.
 */
final readonly class EmulatedSocialIdentityAcquirer implements SocialIdentityAcquirer
{
    /**
     * Report configured providers as available to the readiness fixture.
     */
    public function available(?SocialProviderDefinition $provider = null): bool
    {
        return true;
    }

    /**
     * Return a deterministic authorization endpoint for route-level emulation.
     */
    public function authorizationUrl(
        SocialProviderDefinition $provider,
        SocialAuthorizationRequest $request,
    ): SecretValue {
        return new SecretValue('https://oauth.example.test/authorize');
    }

    /**
     * Refuse credential exchange because that remains provider-specific consumer work.
     */
    public function acquire(
        SocialProviderDefinition $provider,
        SocialCallbackRequest $request,
    ): AcquiredSocialIdentity {
        throw new RuntimeException(
            'The emulated consumer does not exchange external OAuth credentials.',
        );
    }
}
