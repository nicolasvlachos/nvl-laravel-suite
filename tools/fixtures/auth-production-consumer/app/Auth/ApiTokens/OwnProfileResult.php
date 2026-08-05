<?php

declare(strict_types=1);

namespace App\Auth\ApiTokens;

/**
 * Carries the host-owned profile fields exposed to an authorized bearer.
 */
final readonly class OwnProfileResult
{
    /**
     * Create one immutable profile projection.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}

    /**
     * Return the stable HTTP representation.
     *
     * @return array{id: string, name: string, email: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
