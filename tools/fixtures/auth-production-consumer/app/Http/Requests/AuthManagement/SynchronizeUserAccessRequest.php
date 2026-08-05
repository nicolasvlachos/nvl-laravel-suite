<?php

declare(strict_types=1);

namespace App\Http\Requests\AuthManagement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates complete user access replacement input.
 */
final class SynchronizeUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:20'],
            'roles.*' => ['required', 'string', 'max:100', 'distinct'],
            'permissions' => ['present', 'array', 'max:50'],
            'permissions.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
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
