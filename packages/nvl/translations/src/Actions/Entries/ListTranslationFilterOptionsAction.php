<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Entries;

use LogicException;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Lists distinct translation filter options from one entry query.
 */
final class ListTranslationFilterOptionsAction
{
    /**
     * Return sorted option values for the translation index.
     *
     * @return array{scopeTypes: list<string>, scopeNames: list<string>, locales: list<string>, groups: list<string>} Translation filter options
     */
    public function execute(): array
    {
        return [
            'scopeTypes' => $this->values('scope_type'),
            'scopeNames' => $this->values('scope_name'),
            'locales' => $this->values('locale'),
            'groups' => $this->values('group', '*'),
        ];
    }

    /**
     * Query sorted non-empty distinct values for one allowlisted attribute.
     *
     * @param  string  $attribute  Model attribute name
     * @param  string|null  $excludedValue  Optional value to exclude
     * @return list<string> Sorted unique values
     */
    private function values(string $attribute, ?string $excludedValue = null): array
    {
        $column = match ($attribute) {
            'scope_type', 'scope_name', 'locale', 'group' => $attribute,
            default => throw new LogicException("Unsupported translation option column [{$attribute}]."),
        };
        $query = TranslationEntry::query()
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '');

        if ($excludedValue !== null) {
            $query->where($column, '!=', $excludedValue);
        }

        return array_values($query
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->values()
            ->all());
    }
}
