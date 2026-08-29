<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Entries;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Data\TranslationCatalogStatisticsData;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Returns bounded health statistics for an authorized filtered translation catalog.
 */
final class GetTranslationCatalogStatisticsAction
{
    /**
     * Create the catalog statistics action.
     */
    public function __construct(
        private readonly TranslationsAuthorization $authorization,
    ) {}

    /**
     * Execute the authorized catalog statistics query.
     */
    public function execute(?FilterSet $filters = null): TranslationCatalogStatisticsData
    {
        $this->authorization->authorize(TranslationsAbility::ListEntries);

        $query = TranslationEntry::query()
            ->applyFilterSet($filters ?? FilterSet::none())
            ->reorder();
        $totals = $this->totals($query);

        return new TranslationCatalogStatisticsData(
            total: $totals['total'],
            missing: $totals['missing'],
            conflicts: $totals['conflicts'],
            changed: $totals['changed'],
            locales: $this->locales($query),
            scopes: $this->scopes($query),
        );
    }

    /**
     * Return the four scalar statistics in one aggregate query.
     *
     * @param  Builder<TranslationEntry>  $query
     * @return array{total: int, missing: int, conflicts: int, changed: int}
     */
    private function totals(Builder $query): array
    {
        $row = (clone $query)
            ->toBase()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN is_missing = ? THEN 1 ELSE 0 END) AS missing_count', [true])
            ->selectRaw('SUM(CASE WHEN sync_status = ? THEN 1 ELSE 0 END) AS conflict_count', [
                TranslationSyncStatus::Conflict->value,
            ])
            ->selectRaw('SUM(CASE WHEN source_hash IS NOT NULL AND sync_status IN (?, ?) THEN 1 ELSE 0 END) AS changed_count', [
                TranslationSyncStatus::Edited->value,
                TranslationSyncStatus::Conflict->value,
            ])
            ->first();
        $values = is_object($row) ? (array) $row : [];

        return [
            'total' => self::normalizeCount($values['total_count'] ?? null),
            'missing' => self::normalizeCount($values['missing_count'] ?? null),
            'conflicts' => self::normalizeCount($values['conflict_count'] ?? null),
            'changed' => self::normalizeCount($values['changed_count'] ?? null),
        ];
    }

    /**
     * Return the top one hundred locale counts.
     *
     * @param  Builder<TranslationEntry>  $query
     * @return array<string, int>
     */
    private function locales(Builder $query): array
    {
        $rows = (clone $query)
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(TRIM(locale), ''), 'unknown') AS aggregate_key")
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy('aggregate_key')
            ->orderByDesc('aggregate_count')
            ->orderBy('aggregate_key')
            ->limit(100)
            ->get();
        $locales = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $key = $values['aggregate_key'] ?? 'unknown';

            $locales[is_string($key) && $key !== '' ? $key : 'unknown'] = self::normalizeCount(
                $values['aggregate_count'] ?? null,
            );
        }

        return $locales;
    }

    /**
     * Return the top one hundred canonical scope-token counts.
     *
     * @param  Builder<TranslationEntry>  $query
     * @return array<string, int>
     */
    private function scopes(Builder $query): array
    {
        $typeExpression = "COALESCE(NULLIF(TRIM(scope_type), ''), 'unknown')";
        $nameExpression = "CASE WHEN COALESCE(NULLIF(TRIM(scope_type), ''), 'unknown') IN ('app', 'unknown') THEN '' ELSE COALESCE(TRIM(scope_name), '') END";
        $rows = (clone $query)
            ->toBase()
            ->selectRaw("{$typeExpression} AS aggregate_type")
            ->selectRaw("{$nameExpression} AS aggregate_name")
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy('aggregate_type', 'aggregate_name')
            ->orderByDesc('aggregate_count')
            ->orderBy('aggregate_type')
            ->orderBy('aggregate_name')
            ->limit(100)
            ->get();
        $scopes = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $type = is_string($values['aggregate_type'] ?? null) ? trim($values['aggregate_type']) : '';
            $name = is_string($values['aggregate_name'] ?? null) ? trim($values['aggregate_name']) : '';
            $key = self::scopeKey($type, $name);
            $scopes[$key] = ($scopes[$key] ?? 0) + self::normalizeCount($values['aggregate_count'] ?? null);
        }

        return $scopes;
    }

    /**
     * Build the same scope token used by translation commands.
     */
    private static function scopeKey(string $type, string $name): string
    {
        if ($type === TranslationScopeType::App->value) {
            return TranslationScopeType::App->value;
        }

        if ($type === '') {
            return 'unknown';
        }

        return $name !== '' ? "{$type}:{$name}" : $type;
    }

    /**
     * Normalize database-driver aggregate values to non-negative integers.
     */
    private static function normalizeCount(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        $validated = is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;

        return is_int($validated) ? $validated : 0;
    }
}
