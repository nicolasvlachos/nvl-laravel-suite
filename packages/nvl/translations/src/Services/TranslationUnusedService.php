<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Support\TranslationScope;

/**
 * Computes unused translation entries from latest scan records.
 */
final class TranslationUnusedService
{
    /**
     * @param  TranslationScopeResolver  $scopeResolver  Scope resolver service
     */
    public function __construct(
        private readonly TranslationScopeResolver $scopeResolver,
    ) {}

    /**
     * Generate unused translation report.
     *
     * @param  list<string>  $scopeTokens
     * @return array{
     *     scanned_at:CarbonImmutable|null,
     *     total:int,
     *     rows:list<array{
     *         id:string,
     *         scope_type:string,
     *         scope_name:string,
     *         locale:string,
     *         format:string,
     *         group:string|null,
     *         key:string,
     *         full_key:string
     *     }>
     * }
     */
    public function execute(array $scopeTokens = [], int $days = 0): array
    {
        $entryQuery = TranslationEntry::query()
            ->where('is_missing', false)
            ->orderBy('scope_type')
            ->orderBy('scope_name')
            ->orderBy('locale')
            ->orderBy('group')
            ->orderBy('key');
        $scopes = $this->scopeResolver->resolveScopes($scopeTokens);
        $this->applyScopeConstraint($entryQuery, $scopes);

        $usageQuery = TranslationUsage::query();
        $this->applyScopeConstraint($usageQuery, $scopes);
        $scannedAt = null;

        if ($days > 0) {
            $scannedAt = CarbonImmutable::now()->subDays($days);
            $usageQuery->where('last_seen_at', '>=', $scannedAt);
        } else {
            $latestScan = TranslationScanRun::query()
                ->orderByDesc('scanned_at')
                ->orderByDesc('created_at')
                ->first(['id', 'scanned_at']);

            if ($latestScan !== null) {
                $scannedAt = $latestScan->scanned_at->toImmutable();
                $usageQuery->where('scan_id', $latestScan->id);
            } else {
                $usageQuery->whereRaw('1 = 0');
            }
        }

        $usedScoped = [];

        foreach ($usageQuery->get(['scope_type', 'scope_name', 'format', 'full_key']) as $usage) {
            $fullKey = (string) $usage->full_key;
            if ($usage->scope_type === null || $usage->scope_name === null) {
                continue;
            }

            $scopedKey = sprintf(
                '%s|%s|%s|%s',
                (string) $usage->scope_type,
                (string) $usage->scope_name,
                (string) $usage->format,
                $fullKey,
            );
            $usedScoped[$scopedKey] = true;
        }

        $configuredAllowlist = Config::get('translations.scan_allowlist', []);
        $allowlist = is_array($configuredAllowlist)
            ? array_values(array_filter(
                $configuredAllowlist,
                static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
            ))
            : [];

        $unused = [];
        foreach ($entryQuery->cursor() as $entry) {
            $fullKey = $entry->format === 'php' && $entry->group !== '*'
                ? $entry->group.'.'.$entry->key
                : (string) $entry->key;

            if ($this->matchesAllowlist($fullKey, $allowlist)) {
                continue;
            }

            $scopedKey = sprintf(
                '%s|%s|%s|%s',
                (string) $entry->scope_type,
                (string) $entry->scope_name,
                (string) $entry->format,
                $fullKey,
            );
            if (isset($usedScoped[$scopedKey])) {
                continue;
            }

            $unused[] = [
                'id' => (string) $entry->id,
                'scope_type' => (string) $entry->scope_type,
                'scope_name' => (string) $entry->scope_name,
                'locale' => (string) $entry->locale,
                'format' => (string) $entry->format,
                'group' => $entry->group === '*' ? null : $entry->group,
                'key' => (string) $entry->key,
                'full_key' => $fullKey,
            ];
        }

        return [
            'scanned_at' => $scannedAt instanceof CarbonImmutable ? $scannedAt : null,
            'total' => count($unused),
            'rows' => $unused,
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<TranslationScope>  $scopes
     */
    private function applyScopeConstraint(Builder $query, array $scopes): void
    {
        if ($scopes === []) {
            return;
        }

        $query->where(static function (Builder $builder) use ($scopes): void {
            foreach ($scopes as $scope) {
                $builder->orWhere(static function (Builder $nested) use ($scope): void {
                    $nested->getQuery()
                        ->where('scope_type', $scope->type->value)
                        ->where('scope_name', $scope->name);
                });
            }
        });
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAllowlist(string $fullKey, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (Str::is($pattern, $fullKey)) {
                return true;
            }
        }

        return false;
    }
}
