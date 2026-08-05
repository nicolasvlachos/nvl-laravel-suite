<?php

declare(strict_types=1);

namespace App\Auth\Credentials;

/**
 * Captures host password update, retry, checkpoint, and verification behavior.
 */
final readonly class CredentialAdapterProbeResult
{
    public function __construct(
        public bool $updated,
        public bool $retryIdempotent,
        public bool $checkpointUnique,
        public bool $verified,
    ) {}

    /**
     * Determine whether both consumer credential adapters are production-capable.
     */
    public function healthy(): bool
    {
        return $this->updated
            && $this->retryIdempotent
            && $this->checkpointUnique
            && $this->verified;
    }
}
