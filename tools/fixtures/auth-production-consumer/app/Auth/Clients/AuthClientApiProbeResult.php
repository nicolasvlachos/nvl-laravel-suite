<?php

declare(strict_types=1);

namespace App\Auth\Clients;

use Nvl\Auth\ValueObjects\SecretValue;

/**
 * Captures one managed client and its ephemeral hosted-flow continuation.
 */
final readonly class AuthClientApiProbeResult
{
    /**
     * Create one registered-client lifecycle evidence snapshot.
     *
     * @param  array<string, int>  $statuses
     */
    public function __construct(
        public string $projectionId,
        public string $clientId,
        public string $origin,
        public SecretValue $binding,
        public SecretValue $clientGrant,
        public bool $oneTimeMaterialProtected,
        public array $statuses,
    ) {}

    /**
     * Determine whether scoped management and hosted start completed safely.
     */
    public function healthy(): bool
    {
        return count($this->statuses) === 10
            && $this->oneTimeMaterialProtected;
    }

    /**
     * Return the same flow evidence with terminal cleanup statuses attached.
     */
    public function withCleanupEvidence(
        int $destroyStatus,
        int $deletedShowStatus,
    ): self {
        return new self(
            projectionId: $this->projectionId,
            clientId: $this->clientId,
            origin: $this->origin,
            binding: $this->binding,
            clientGrant: $this->clientGrant,
            oneTimeMaterialProtected: $this->oneTimeMaterialProtected,
            statuses: [
                ...$this->statuses,
                'clients.destroy' => $destroyStatus,
                'clients.show.deleted' => $deletedShowStatus,
            ],
        );
    }
}
