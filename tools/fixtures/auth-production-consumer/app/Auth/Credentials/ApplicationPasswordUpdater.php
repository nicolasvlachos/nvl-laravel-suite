<?php

declare(strict_types=1);

namespace App\Auth\Credentials;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Nvl\Auth\Contracts\PasswordUpdater;
use Nvl\Auth\Results\PasswordUpdateResult;
use Nvl\Auth\ValueObjects\PasswordUpdateRequest;

/**
 * Applies recovery-authorized password replacement exactly once per operation.
 */
final readonly class ApplicationPasswordUpdater implements PasswordUpdater
{
    public function __construct(private Hasher $hasher) {}

    /**
     * Persist one password update and its host-owned idempotency checkpoint.
     */
    public function update(PasswordUpdateRequest $request): PasswordUpdateResult
    {
        return DB::transaction(function () use ($request): PasswordUpdateResult {
            $existing = DB::table('auth_consumer_password_operations')
                ->where('operation_id', $request->operationId)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                return PasswordUpdateResult::applied();
            }

            $user = $this->user($request);

            if (! $user instanceof User) {
                return PasswordUpdateResult::failed(
                    reasonCode: 'principal_unavailable',
                    retryable: false,
                );
            }

            $user->forceFill([
                'password' => $this->hasher->make($request->password->reveal()),
            ])->save();
            DB::table('auth_consumer_password_operations')->insert([
                'operation_id' => $request->operationId,
                'user_id' => $user->getKey(),
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return PasswordUpdateResult::applied();
        }, 3);
    }

    /**
     * Resolve the exact consumer subject named by the package principal.
     */
    private function user(PasswordUpdateRequest $request): ?User
    {
        if ($request->principal->subjectType !== (new User)->getMorphClass()) {
            return null;
        }

        return User::query()->find($request->principal->subjectId);
    }
}
