<?php

declare(strict_types=1);

namespace App\Auth\Authorization;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;

/** Applies the host permission to every Settings management operation. */
final class AuthConsumerSettingsAuthorization implements SettingsAuthorization
{
    public function authorize(SettingAbility $ability, ?string $key = null): void
    {
        $actor = Auth::user();

        if (! $actor instanceof User
            || ! $actor->hasPermissionTo(AuthConsumerAccess::PERMISSION)) {
            throw new AuthorizationException(
                'The actor cannot manage Auth consumer settings.',
            );
        }
    }
}
