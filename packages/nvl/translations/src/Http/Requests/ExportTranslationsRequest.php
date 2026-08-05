<?php

declare(strict_types=1);

namespace Nvl\Translations\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Nvl\Translations\Rules\StringOrList;

/**
 * Validates and normalizes workspace-to-file export options.
 */
final class ExportTranslationsRequest extends FormRequest
{
    /**
     * Leave authorization to the package's explicit authorization boundary.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Declare export request validation rules.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', new StringOrList(maximumItemLength: 255)],
            'scope.*' => ['string', 'max:255'],
            'locales' => ['nullable', new StringOrList(maximumItemLength: 35)],
            'locales.*' => ['string', 'max:35'],
            'format' => ['nullable', 'string', Rule::in(['php', 'json', 'both'])],
            'target' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'prune' => ['nullable', 'boolean'],
            'dryRun' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Add the explicit file-replacement confirmation invariant.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->dryRun() && ! $this->boolean('force')) {
                    $validator->errors()->add(
                        'force',
                        trans('translations::translations/validation.force_required'),
                    );
                }
            },
        ];
    }

    /**
     * Return normalized configured scope tokens.
     *
     * @return list<string>
     */
    public function scopeTokens(): array
    {
        return $this->normalizeList($this->validated('scope', [])) ?? [];
    }

    /**
     * Return normalized locale names or all locales.
     *
     * @return list<string>|null
     */
    public function locales(): ?array
    {
        return $this->normalizeList($this->validated('locales'));
    }

    /**
     * Return the validated export format.
     */
    public function translationFormat(): string
    {
        $format = $this->validated('format', 'both');

        return is_string($format) ? $format : 'both';
    }

    /**
     * Return the configured named target.
     */
    public function target(): string
    {
        $target = $this->validated('target', 'source');

        return is_string($target) && trim($target) !== '' ? trim($target) : 'source';
    }

    /**
     * Determine whether stale destination files may be removed.
     */
    public function prune(): bool
    {
        return $this->boolean('prune');
    }

    /**
     * Determine whether the export is a simulation.
     */
    public function dryRun(): bool
    {
        return $this->boolean('dryRun');
    }

    /**
     * Normalize one string or list option.
     *
     * @return list<string>|null
     */
    private function normalizeList(mixed $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }

        $values = is_string($input) ? explode(',', $input) : $input;

        if (! is_array($values)) {
            return null;
        }

        $normalized = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $values,
            ),
            static fn (string $value): bool => $value !== '',
        ));

        return $normalized === [] ? null : $normalized;
    }
}
