<?php

declare(strict_types=1);

namespace Nvl\Translations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nvl\Translations\Rules\StringOrList;

/**
 * Validates and normalizes file-to-workspace import options.
 */
final class ImportTranslationsRequest extends FormRequest
{
    /**
     * Leave authorization to the package's explicit authorization boundary.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Declare import request validation rules.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', new StringOrList(maximumItemLength: 255)],
            'scope.*' => ['string', 'max:255'],
            'format' => ['nullable', 'string', Rule::in(['php', 'json', 'both'])],
            'dryRun' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Return normalized configured scope tokens.
     *
     * @return list<string>
     */
    public function scopeTokens(): array
    {
        return $this->normalizeList($this->validated('scope', []));
    }

    /**
     * Return the validated import format.
     */
    public function translationFormat(): string
    {
        $format = $this->validated('format', 'both');

        return is_string($format) ? $format : 'both';
    }

    /**
     * Determine whether the import is a simulation.
     */
    public function dryRun(): bool
    {
        return $this->boolean('dryRun');
    }

    /**
     * Normalize one string or list option.
     *
     * @return list<string>
     */
    private function normalizeList(mixed $input): array
    {
        $values = is_string($input) ? explode(',', $input) : $input;

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $values,
            ),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
