<?php

declare(strict_types=1);

namespace App\Auth\ApiTokens;

/**
 * Captures managed personal-token issuance and bearer-authorization evidence.
 */
final readonly class ApiTokenApiProbeResult
{
    /**
     * Create one complete token lifecycle evidence snapshot.
     *
     * @param  array<string, int>  $statuses
     */
    public function __construct(
        public string $tokenId,
        public string $sessionId,
        public bool $oneTimeMaterialProtected,
        public bool $managedBearerAccepted,
        public bool $wrongAbilityDenied,
        public bool $unmanagedBearerRejected,
        public bool $rotatedCredentialRejected,
        public bool $singlyRevokedBearerRejected,
        public bool $revokedBearerRejected,
        public int $bulkRevokedCount,
        public array $statuses,
    ) {}

    /**
     * Determine whether issuance, abilities, lifecycle, and lineage are enforced.
     */
    public function healthy(): bool
    {
        return $this->oneTimeMaterialProtected
            && $this->managedBearerAccepted
            && $this->wrongAbilityDenied
            && $this->unmanagedBearerRejected
            && $this->rotatedCredentialRejected
            && $this->singlyRevokedBearerRejected
            && $this->revokedBearerRejected
            && $this->bulkRevokedCount >= 2
            && count($this->statuses) === 16;
    }
}
