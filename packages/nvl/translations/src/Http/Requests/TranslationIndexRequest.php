<?php

declare(strict_types=1);

namespace Nvl\Translations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates translation catalog pagination and filter query input.
 */
final class TranslationIndexRequest extends FormRequest
{
    /**
     * Leave authorization to the package's explicit authorization boundary.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Declare catalog query validation rules.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'filter' => ['nullable', 'array', 'max:25'],
            'sort' => ['nullable'],
        ];
    }

    /**
     * Resolve the requested page size after validation.
     */
    public function perPage(int $fallback = 50): int
    {
        $value = $this->validated('per_page', $this->validated('limit', $fallback));

        return is_numeric($value)
            ? max(1, min(200, (int) $value))
            : $fallback;
    }

    /**
     * Return string-keyed query parameters for the filter adapter.
     *
     * @return array<string, mixed>
     */
    public function filterQuery(): array
    {
        $parameters = [];

        foreach ($this->query() as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }
}
