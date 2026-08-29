<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;

/** Applies the host permission to every delivery-history projection. */
final class AuthConsumerMailReadAuthorization implements MailNotificationReadAuthorization
{
    public function authorize(
        MailNotificationReadAbility $ability,
        Authenticatable $actor,
        ?MailNotification $notification = null,
    ): void {
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo(AuthConsumerAccess::PERMISSION)) {
            throw new AuthorizationException(
                'The actor cannot read Auth consumer delivery history.',
            );
        }
    }
}
