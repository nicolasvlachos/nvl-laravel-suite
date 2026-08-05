<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use LogicException;
use Nvl\Seo\Support\SeoModelIdentifier;

/**
 * Provides typed access to validated SEO management input.
 */
abstract class SeoManagementRequest extends FormRequest
{
    /**
     * Defer authorization to the package's context-rich authorization contract.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return the request-specific validation contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    abstract public function rules(): array;

    /**
     * Reject unrecognized top-level input instead of silently discarding it.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = [];

                foreach (array_keys($this->rules()) as $field) {
                    $allowed[] = Str::before($field, '.');
                }

                foreach (array_keys($this->all()) as $field) {
                    if (is_string($field) && ! in_array($field, $allowed, true)) {
                        $validator->errors()->add(
                            $field,
                            "The {$field} field is not supported.",
                        );
                    }
                }
            },
        ];
    }

    /**
     * Return one required validated string.
     */
    protected function requiredString(string $key): string
    {
        $value = $this->validated($key);

        if (! is_string($value) || $value === '') {
            throw new LogicException("Validated SEO field [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * Return one required normalized string or integer identifier.
     */
    protected function requiredIdentifier(string $key): string
    {
        $value = $this->validated($key);

        if (! is_string($value) && ! is_int($value)) {
            throw new LogicException(
                "Validated SEO field [{$key}] must be a string or integer identifier.",
            );
        }

        return SeoModelIdentifier::normalize($value);
    }

    /**
     * Return one optional validated string.
     */
    protected function optionalString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Return one required validated integer.
     */
    protected function requiredInteger(string $key): int
    {
        $value = $this->validated($key);

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        if (! is_int($value)) {
            throw new LogicException("Validated SEO field [{$key}] must be an integer.");
        }

        return $value;
    }

    /**
     * Return one required validated boolean.
     */
    protected function requiredBoolean(string $key): bool
    {
        $value = filter_var(
            $this->validated($key),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($value === null) {
            throw new LogicException("Validated SEO field [{$key}] must be a boolean.");
        }

        return $value;
    }

    /**
     * Return one optional validated boolean.
     */
    protected function optionalBoolean(string $key): bool
    {
        return $this->has($key) && $this->requiredBoolean($key);
    }

    /**
     * Return one required validated object.
     *
     * @return array<string, mixed>
     */
    protected function requiredArray(string $key): array
    {
        $value = $this->validated($key);

        if (! is_array($value)) {
            throw new LogicException("Validated SEO field [{$key}] must be an object.");
        }

        $normalized = [];

        foreach ($value as $field => $fieldValue) {
            if (is_string($field)) {
                $normalized[$field] = $fieldValue;
            }
        }

        return $normalized;
    }
}
