<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Nvl\Auth\ValueObjects\SecretValue;

/**
 * Validates the clean consumer's local session-login probe input.
 */
final class AuthConsumerSessionRequest extends FormRequest
{
    /**
     * Return bounded fixture credential rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * Return the validated email address.
     */
    public function email(): string
    {
        return $this->string('email')->toString();
    }

    /**
     * Return the validated password as protected one-request material.
     */
    public function password(): SecretValue
    {
        return new SecretValue($this->string('password')->toString());
    }
}
