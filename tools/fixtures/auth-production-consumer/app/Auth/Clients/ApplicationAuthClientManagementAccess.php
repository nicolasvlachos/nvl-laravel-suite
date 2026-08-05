<?php

declare(strict_types=1);

namespace App\Auth\Clients;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Auth\Contracts\AuthClientManagementAccess;
use Nvl\Auth\Data\Mutations\Clients\CreateAuthClientData;
use Nvl\Auth\Data\Mutations\Clients\SetAuthClientStatusData;
use Nvl\Auth\Data\Mutations\Clients\UpdateAuthClientData;
use Nvl\Auth\Models\AuthClient;

/**
 * Applies consumer authorization before registered-client queries and mutations.
 */
final readonly class ApplicationAuthClientManagementAccess implements AuthClientManagementAccess
{
    /** @return Builder<AuthClient> */
    public function clientsForRead(Authenticatable $actor): Builder
    {
        $this->authorize($actor);

        return AuthClient::query();
    }

    /**
     * Authorize one canonical registered-client creation.
     */
    public function authorizeCreation(
        Authenticatable $actor,
        CreateAuthClientData $data,
    ): void {
        $this->authorize($actor);
    }

    /** @return Builder<AuthClient> */
    public function clientsForUpdate(
        Authenticatable $actor,
        UpdateAuthClientData $data,
    ): Builder {
        $this->authorize($actor);

        return AuthClient::query();
    }

    /** @return Builder<AuthClient> */
    public function clientsForStatusChange(
        Authenticatable $actor,
        SetAuthClientStatusData $data,
    ): Builder {
        $this->authorize($actor);

        return AuthClient::query();
    }

    /** @return Builder<AuthClient> */
    public function clientsForDeletion(Authenticatable $actor): Builder
    {
        $this->authorize($actor);

        return AuthClient::query();
    }

    /**
     * Require the concrete consumer actor and client-management permission.
     */
    private function authorize(Authenticatable $actor): void
    {
        if (! $actor instanceof User || ! $actor->can('auth.clients.manage')) {
            throw new AuthorizationException;
        }
    }
}
