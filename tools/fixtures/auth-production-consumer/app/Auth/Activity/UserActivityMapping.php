<?php

declare(strict_types=1);

namespace App\Auth\Activity;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Contracts\ActivityMapping;
use Spatie\Activitylog\Support\LogOptions;

/** Presents application principal activity without package model queries. */
final class UserActivityMapping implements ActivityMapping
{
    /** @return class-string<User> */
    public function modelClass(): string
    {
        return User::class;
    }

    public function entityLabel(): string
    {
        return 'Consumer user';
    }

    public function subjectLabel(Model $subject): string
    {
        if ($subject instanceof User) {
            return $subject->name;
        }

        $identifier = $subject->getKey();

        return is_string($identifier) || is_int($identifier)
            ? (string) $identifier
            : class_basename($subject);
    }

    public function logName(): string
    {
        return 'auth-consumer-users';
    }

    public function logOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function fieldLabel(string $key): string
    {
        return str_replace('_', ' ', ucfirst($key));
    }

    public function fieldValue(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    public function eventDisplayValue(string $event, array $properties): ?string
    {
        return null;
    }

    /** @return array<string, string> */
    public function eventTemplates(): array
    {
        return [];
    }
}
