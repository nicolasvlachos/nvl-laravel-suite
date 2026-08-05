<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Nvl\Auth\Traits\HasAuthAccess;

/**
 * Represents the consumer-owned authenticatable and business authorization subject.
 *
 * @property int $id Consumer-owned user identifier.
 * @property string $name Display name.
 * @property string $email Canonical email address.
 * @property CarbonInterface|null $email_verified_at Email verification time.
 * @property string $password Hashed application credential.
 * @property string|null $remember_token Persistent-login rotation token.
 * @property CarbonInterface|null $created_at Creation time.
 * @property CarbonInterface|null $updated_at Last update time.
 */
final class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasAuthAccess;

    use HasFactory;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Return consumer-owned credential casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }
}
