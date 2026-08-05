<?php

declare(strict_types=1);

namespace App\Auth\Management;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Auth\Contracts\ManagementAccess;
use Nvl\Auth\Data\Invitations\CreateInvitationData;
use Nvl\Auth\Models\Invitation;
use Nvl\Auth\Models\Principal;
use Nvl\Auth\Models\RecoveryCase;
use Nvl\Auth\Models\SecurityEvent;

/**
 * Applies consumer authorization before package management queries or mutations.
 */
final readonly class ApplicationManagementAccess implements ManagementAccess
{
    /** @return Builder<Invitation> */
    public function invitations(Authenticatable $actor): Builder
    {
        $this->authorize($actor, 'auth.invitations.manage');

        return Invitation::query();
    }

    /** @return Builder<Principal> */
    public function principals(Authenticatable $actor): Builder
    {
        $this->authorize($actor, 'auth.principals.view');

        return Principal::query();
    }

    /** @return Builder<SecurityEvent> */
    public function securityEvents(Authenticatable $actor): Builder
    {
        $this->authorize($actor, 'auth.security-events.view');

        return SecurityEvent::query();
    }

    /** @return Builder<RecoveryCase> */
    public function recoveryCases(Authenticatable $actor): Builder
    {
        $this->authorize($actor, 'auth.recovery.review');

        return RecoveryCase::query();
    }

    /**
     * Authorize invitation creation through the same host policy as later mutations.
     */
    public function authorizeInvitationCreation(
        Authenticatable $actor,
        CreateInvitationData $data,
    ): void {
        $this->authorize($actor, 'auth.invitations.manage');
    }

    /**
     * Require the concrete consumer subject and one deterministic permission.
     */
    private function authorize(Authenticatable $actor, string $permission): void
    {
        if (! $actor instanceof User || ! $actor->can($permission)) {
            throw new AuthorizationException;
        }
    }
}
