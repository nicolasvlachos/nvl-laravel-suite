<?php

declare(strict_types=1);

namespace Nvl\Forms\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Support\Facades\Gate;
use Nvl\Forms\Models\FormEntry;

/**
 * Authorization policy for form entry management.
 */
final class FormEntryPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any form entries.
     *
     * @param  User|null  $user  Authenticated user
     * @return bool True when the user can view entries
     */
    public function viewAny(?User $user): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can view the form entry.
     *
     * @param  User|null  $user  Authenticated user
     * @param  FormEntry  $entry  Form entry record
     * @return bool True when the user can view the entry
     */
    public function view(?User $user, FormEntry $entry): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can create form entries.
     *
     * @param  User|null  $user  Authenticated user
     * @return bool True when the user can create entries
     */
    public function create(?User $user): bool
    {
        // Public form submissions don't require authentication
        // This policy method is for admin-created entries
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can delete the form entry.
     *
     * @param  User|null  $user  Authenticated user
     * @param  FormEntry  $entry  Form entry record
     * @return bool True when the user can delete the entry
     */
    public function delete(?User $user, FormEntry $entry): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can export form entries.
     *
     * @param  User|null  $user  Authenticated user
     * @return bool True when the user can export entries
     */
    public function export(?User $user): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user has access through the configured management gate.
     *
     * @param  User|null  $user  Authenticated user
     * @return bool True when the authenticated user passes the configured gate
     */
    private function isAuthoritative(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $gate = config('forms.authorization.gate');

        if (! is_string($gate) || $gate === '') {
            return false;
        }

        return Gate::forUser($user)->allows($gate);
    }
}
