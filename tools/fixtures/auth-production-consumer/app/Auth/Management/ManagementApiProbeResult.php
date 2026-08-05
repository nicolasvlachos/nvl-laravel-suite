<?php

declare(strict_types=1);

namespace App\Auth\Management;

/**
 * Captures the representative consumer management API exercise.
 */
final readonly class ManagementApiProbeResult
{
    /**
     * @param  array<string, int>  $statuses
     */
    public function __construct(
        public bool $authorizationProtected,
        public bool $passwordChangeInvalidatedTrust,
        public array $statuses,
    ) {}

    /**
     * Determine whether every host management route completed as expected.
     */
    public function healthy(): bool
    {
        return $this->authorizationProtected
            && $this->passwordChangeInvalidatedTrust
            && count($this->statuses) === 9;
    }
}
