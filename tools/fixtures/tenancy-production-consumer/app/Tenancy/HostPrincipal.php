<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;

/** Represents one scalar host principal in the standalone Media profile. */
final readonly class HostPrincipal implements Authenticatable
{
    /** Create a host principal with one stable identifier. */
    public function __construct(private string $identifier) {}

    /** Return the host authentication identifier column name. */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /** Return the stable host authentication identifier. */
    public function getAuthIdentifier(): string
    {
        return $this->identifier;
    }

    /** Return no password for the non-login fixture principal. */
    public function getAuthPassword(): string
    {
        return '';
    }

    /** Return the conventional password column name. */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /** Return no remember token for the non-login fixture principal. */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /** Ignore remember-token mutation for the non-login fixture principal. */
    public function setRememberToken($value): void {}

    /** Return the conventional remember-token column name. */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
