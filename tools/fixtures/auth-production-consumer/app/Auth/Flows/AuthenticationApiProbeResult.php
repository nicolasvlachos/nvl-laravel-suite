<?php

declare(strict_types=1);

namespace App\Auth\Flows;

/**
 * Captures the canonical public authentication and session exchange exercise.
 */
final readonly class AuthenticationApiProbeResult
{
    /**
     * @param  array<string, int>  $statuses
     */
    public function __construct(
        public string $flowId,
        public string $sessionId,
        public string $sessionDriver,
        public bool $oneTimeSecretsProtected,
        public array $statuses,
    ) {}

    /**
     * Determine whether every canonical public operation completed safely.
     */
    public function healthy(): bool
    {
        return count($this->statuses) === 4
            && $this->sessionDriver === 'laravel_guard'
            && $this->oneTimeSecretsProtected;
    }
}
