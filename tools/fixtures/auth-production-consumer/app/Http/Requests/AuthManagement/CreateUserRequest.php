<?php

declare(strict_types=1);

namespace App\Http\Requests\AuthManagement;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates consumer user creation input.
 */
final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*' => ['required', 'string', 'max:100', 'distinct'],
            'permissions' => ['sometimes', 'array', 'max:50'],
            'permissions.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->stringList('roles');
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->stringList('permissions');
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $values = $this->validated($key, []);

        return is_array($values)
            ? array_values(array_filter($values, 'is_string'))
            : [];
    }
}
