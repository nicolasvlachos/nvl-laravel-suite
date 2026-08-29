<?php

declare(strict_types=1);

namespace App\Models;

use LogicException;
use Nvl\MailNotifications\Contracts\MailTrackable;

/**
 * Application principal backed by the package-owned Auth identity table.
 */
final class User extends \Nvl\Auth\Models\User implements MailTrackable
{
    /** Return the stable host alias used by Mail Notifications. */
    public function mailNotificationType(): string
    {
        return 'consumer-user';
    }

    /** Return the stable package principal identifier. */
    public function mailNotificationIdentifier(): string
    {
        $identifier = $this->getKey();

        if (! is_string($identifier) || $identifier === '') {
            throw new LogicException('The Auth consumer user has no stable UUID.');
        }

        return $identifier;
    }
}
