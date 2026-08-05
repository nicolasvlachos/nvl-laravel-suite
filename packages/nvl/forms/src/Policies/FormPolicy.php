<?php

declare(strict_types=1);

namespace Nvl\Forms\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Support\Facades\Gate;
use Nvl\Forms\Models\Form;

/**
 * Authorization policy for form management.
 */
final class FormPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any forms.
     *
     * @param  User|null  $user  Authenticated user
     * @return bool True when the user can view forms
     */
    public function viewAny(?User $user): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can view the form.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can view the form
     */
    public function view(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can create forms.
     *
     * @param  User|null  $user  Authenticated user
     * @return bool True when the user can create forms
     */
    public function create(?User $user): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can update the form.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can update the form
     */
    public function update(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can delete the form.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can delete the form
     */
    public function delete(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can duplicate the form.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can duplicate the form
     */
    public function duplicate(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can export form entries.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can export data
     */
    public function export(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can view form analytics.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can view analytics
     */
    public function viewAnalytics(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can manage form fields.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can manage fields
     */
    public function manageFields(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can manage allowed origins.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can manage origins
     */
    public function manageOrigins(?User $user, Form $form): bool
    {
        return $this->isAuthoritative($user);
    }

    /**
     * Determine whether the user can manage rate limits.
     *
     * @param  User|null  $user  Authenticated user
     * @param  Form  $form  Form record
     * @return bool True when the user can manage rate limits
     */
    public function manageRateLimits(?User $user, Form $form): bool
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
