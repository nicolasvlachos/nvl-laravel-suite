<?php

declare(strict_types=1);

namespace App\Auth\Invitations;

/**
 * Captures invitation delivery, provisioning, purpose, and retry behavior.
 */
final readonly class InvitationWorkflowProbeResult
{
    public function __construct(
        public string $invitationId,
        public string $acceptanceId,
        public string $principalId,
        public bool $deliveryScheduled,
        public bool $principalProvisioned,
        public bool $purposeApplied,
        public bool $retryIdempotent,
    ) {}

    /**
     * Determine whether the complete invitation integration succeeded.
     */
    public function healthy(): bool
    {
        return $this->deliveryScheduled
            && $this->principalProvisioned
            && $this->purposeApplied
            && $this->retryIdempotent;
    }
}
