<?php

declare(strict_types=1);

namespace Nvl\Translations\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts either one comma-separated string or a list of values.
 */
final class StringOrList implements ValidationRule
{
    /**
     * Create a bounded string-or-list input rule.
     */
    public function __construct(
        private readonly int $maximumItems = 100,
        private readonly int $maximumItemLength = 255,
    ) {}

    /**
     * Validate the public synchronization option shape.
     *
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value)) {
            $items = array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $item): bool => $item !== '',
            ));

            if (count($items) <= $this->maximumItems
                && $this->itemsFitLengthLimit($items)) {
                return;
            }

            $fail('translations::translations/validation.string_or_list_limits')
                ->translate([
                    'attribute' => $attribute,
                    'items' => $this->maximumItems,
                    'length' => $this->maximumItemLength,
                ]);

            return;
        }

        if (is_array($value) && array_is_list($value)) {
            if (count($value) <= $this->maximumItems) {
                return;
            }

            $fail('translations::translations/validation.string_or_list_limits')
                ->translate([
                    'attribute' => $attribute,
                    'items' => $this->maximumItems,
                    'length' => $this->maximumItemLength,
                ]);

            return;
        }

        $fail('translations::translations/validation.string_or_list')
            ->translate(['attribute' => $attribute]);
    }

    /**
     * Determine whether every comma-separated item fits the configured limit.
     *
     * @param  list<string>  $items
     */
    private function itemsFitLengthLimit(array $items): bool
    {
        foreach ($items as $item) {
            if (mb_strlen($item) > $this->maximumItemLength) {
                return false;
            }
        }

        return true;
    }
}
