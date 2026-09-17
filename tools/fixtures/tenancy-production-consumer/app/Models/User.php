<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Global Auth principal used by the full tenant proof profile.
 *
 * @property string $id Stable principal UUID.
 * @property string $name Principal display name.
 * @property string $email Global identity email.
 * @property bool $is_active Whether the principal may authenticate.
 */
final class User extends \Nvl\Auth\Models\User {}
