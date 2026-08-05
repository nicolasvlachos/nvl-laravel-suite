<?php

declare(strict_types=1);

namespace App\Http\Requests\AuthManagement;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates partial consumer user updates.
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email:rfc',
                'max:255',
                Rule::unique(User::class, 'email')->ignore(
                    $user instanceof User ? $user->getKey() : null,
                ),
            ],
            'password' => ['sometimes', 'string', 'min:12', 'max:255'],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->toString() : null;
    }

    public function email(): ?string
    {
        return $this->has('email') ? $this->string('email')->toString() : null;
    }

    public function password(): ?string
    {
        return $this->has('password')
            ? $this->string('password')->toString()
            : null;
    }
}
