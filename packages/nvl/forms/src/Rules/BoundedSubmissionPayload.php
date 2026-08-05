<?php

declare(strict_types=1);

namespace Nvl\Forms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use JsonException;
use Nvl\Forms\Support\FormsConfiguration;

/**
 * Bounds nested public submission data by encoded size, depth, and item count.
 */
final class BoundedSubmissionPayload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail('The :attribute field must be an array.');

            return;
        }

        try {
            $bytes = strlen(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $fail('The :attribute field contains invalid data.');

            return;
        }

        if ($bytes > FormsConfiguration::positiveInteger(
            'forms.submission.max_payload_bytes',
            262144,
        )) {
            $fail('The :attribute field is too large.');

            return;
        }

        [$depth, $items] = $this->measure($value);

        if ($depth > FormsConfiguration::positiveInteger('forms.submission.max_depth', 8)) {
            $fail('The :attribute field is nested too deeply.');
        }

        if ($items > FormsConfiguration::positiveInteger('forms.submission.max_items', 250)) {
            $fail('The :attribute field contains too many items.');
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return array{int, int}
     */
    private function measure(array $value): array
    {
        $maxDepth = 1;
        $items = count($value);

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            [$childDepth, $childItems] = $this->measure($item);
            $maxDepth = max($maxDepth, $childDepth + 1);
            $items += $childItems;
        }

        return [$maxDepth, $items];
    }
}
