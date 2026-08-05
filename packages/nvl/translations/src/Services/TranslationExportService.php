<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Support\TranslationScope;
use Nvl\Translations\Support\TranslationValueHash;

/**
 * Resaves editable database translation rows as configured PHP or JSON files.
 *
 * Source synchronization owns a short database transaction after the durable
 * file batch because each row requires its value-specific source hash.
 *
 * @phpstan-type ArtifactWrite array{path:string,content:string,target_root:string}
 * @phpstan-type ArtifactDelete array{path:string,target_root:string}
 * @phpstan-type CompiledArtifacts array{
 *     written:int,
 *     deleted:int,
 *     writes:list<ArtifactWrite>,
 *     deletes:list<ArtifactDelete>,
 *     rows:Collection<int, TranslationEntry>
 * }
 */
final class TranslationExportService
{
    public function __construct(
        private readonly TranslationScopeResolver $scopeResolver,
        private readonly TranslationPathGuard $paths,
        private readonly TranslationArtifactWriter $artifactWriter,
    ) {}

    /**
     * Export selected scopes and formats to a configured named destination.
     *
     * @param  list<string>  $scopeTokens
     * @param  list<string>|null  $locales
     * @return array{scopes:int,locales:int,files:int,deleted:int,target:string}
     */
    public function execute(
        array $scopeTokens = [],
        ?array $locales = null,
        string $format = 'both',
        string $target = 'source',
        bool $prune = false,
        bool $dryRun = false,
    ): array {
        [$includePhp, $includeJson] = $this->formats($format);
        $scopes = $this->scopeResolver->resolveScopes($scopeTokens);
        $targetRoots = $this->scopeResolver->resolveExportPaths($scopes, $target);
        $selectedLocales = $this->normalizeLocales($locales);
        $filesWritten = 0;
        $filesDeleted = 0;
        $localesTouched = [];
        $synchronizeSource = trim($target) === '' || trim($target) === 'source';
        $writes = [];
        $deletes = [];
        $rowsToSynchronize = new Collection;

        foreach ($scopes as $scope) {
            $targetRoot = $targetRoots[$scope->token()];
            $scopeLocales = $this->resolveLocalesForScope($scope, $selectedLocales);

            foreach ($scopeLocales as $locale) {
                $localesTouched[$scope->token().'|'.$locale] = true;

                if ($includePhp) {
                    $php = $this->exportPhpForScopeLocale(
                        $scope,
                        $targetRoot,
                        $locale,
                        $prune,
                        $synchronizeSource,
                    );
                    $filesWritten += $php['written'];
                    $filesDeleted += $php['deleted'];
                    $writes = [...$writes, ...$php['writes']];
                    $deletes = [...$deletes, ...$php['deletes']];
                    $rowsToSynchronize = $rowsToSynchronize->merge($php['rows']);
                }

                if ($includeJson) {
                    $json = $this->exportJsonForScopeLocale(
                        $scope,
                        $targetRoot,
                        $locale,
                        $prune,
                        $synchronizeSource,
                    );
                    $filesWritten += $json['written'];
                    $filesDeleted += $json['deleted'];
                    $writes = [...$writes, ...$json['writes']];
                    $deletes = [...$deletes, ...$json['deletes']];
                    $rowsToSynchronize = $rowsToSynchronize->merge($json['rows']);
                }
            }
        }

        $this->artifactWriter->validatePlan($writes, $deletes);

        if (! $dryRun) {
            $this->artifactWriter->apply($writes, $deletes);

            if ($synchronizeSource) {
                $this->markSynchronized($rowsToSynchronize);
            }
        }

        return [
            'scopes' => count($scopes),
            'locales' => count($localesTouched),
            'files' => $filesWritten,
            'deleted' => $filesDeleted,
            'target' => trim($target) === '' ? 'source' : trim($target),
        ];
    }

    /**
     * @param  list<string>|null  $selectedLocales
     * @return list<string>
     */
    private function resolveLocalesForScope(TranslationScope $scope, ?array $selectedLocales): array
    {
        if ($selectedLocales !== null) {
            return $selectedLocales;
        }

        $resolved = TranslationEntry::query()
            ->where('scope_type', $scope->type->value)
            ->where('scope_name', $scope->name)
            ->distinct()
            ->orderBy('locale')
            ->pluck('locale')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(fn (string $locale): string => $this->paths->locale($locale))
            ->values()
            ->all();

        return array_values($resolved);
    }

    /**
     * @return CompiledArtifacts
     */
    private function exportPhpForScopeLocale(
        TranslationScope $scope,
        string $targetRoot,
        string $locale,
        bool $prune,
        bool $synchronizeSource,
    ): array {
        $query = TranslationEntry::query()
            ->where('scope_type', $scope->type->value)
            ->where('scope_name', $scope->name)
            ->where('locale', $locale)
            ->where('format', TranslationFormat::Php->value)
            ->where('is_missing', false)
            ->where('group', '!=', '*')
            ->orderBy('group')
            ->orderBy('key');

        $rows = $query->get(['id', 'group', 'key', 'value']);
        $grouped = [];

        foreach ($rows as $row) {
            $group = $this->paths->group($row->group);
            $grouped[$group] ??= ['payload' => [], 'rows' => []];
            $this->undotSet($grouped[$group]['payload'], $row->key, $row->value);
            $grouped[$group]['rows'][] = $row;
        }

        $expectedPaths = [];
        $writes = [];
        $rowsToSynchronize = new Collection;

        foreach ($grouped as $group => $groupData) {
            $payload = $groupData['payload'];
            $this->sortRecursively($payload);
            $targetPath = $this->paths->child($targetRoot, $locale, $group.'.php');
            $writes[] = [
                'path' => $targetPath,
                'content' => $this->renderPhpFile($payload),
                'target_root' => $targetRoot,
            ];
            $expectedPaths[$this->normalizedPath($targetPath)] = true;
            if ($synchronizeSource) {
                $rowsToSynchronize = $rowsToSynchronize->merge($groupData['rows']);
            }
        }

        $deletes = $prune
            ? $this->prunePhpFiles($targetRoot, $locale, $expectedPaths)
            : [];

        return [
            'written' => count($writes),
            'deleted' => count($deletes),
            'writes' => $writes,
            'deletes' => $deletes,
            'rows' => $rowsToSynchronize,
        ];
    }

    /**
     * @return CompiledArtifacts
     */
    private function exportJsonForScopeLocale(
        TranslationScope $scope,
        string $targetRoot,
        string $locale,
        bool $prune,
        bool $synchronizeSource,
    ): array {
        $rows = TranslationEntry::query()
            ->where('scope_type', $scope->type->value)
            ->where('scope_name', $scope->name)
            ->where('locale', $locale)
            ->where('format', TranslationFormat::Json->value)
            ->where('is_missing', false)
            ->orderBy('key')
            ->get(['id', 'key', 'value']);

        $targetPath = $this->paths->child($targetRoot, $locale.'.json');

        if ($rows->isEmpty()) {
            if ($prune && File::exists($targetPath)) {
                return [
                    'written' => 0,
                    'deleted' => 1,
                    'writes' => [],
                    'deletes' => [[
                        'path' => $targetPath,
                        'target_root' => $targetRoot,
                    ]],
                    'rows' => new Collection,
                ];
            }

            return [
                'written' => 0,
                'deleted' => 0,
                'writes' => [],
                'deletes' => [],
                'rows' => new Collection,
            ];
        }

        $payload = [];
        foreach ($rows as $row) {
            $payload[$row->key] = $row->value;
        }
        ksort($payload);

        $json = json_encode(
            $payload,
            JSON_FORCE_OBJECT
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
        );

        return [
            'written' => 1,
            'deleted' => 0,
            'writes' => [[
                'path' => $targetPath,
                'content' => $json."\n",
                'target_root' => $targetRoot,
            ]],
            'deletes' => [],
            'rows' => $synchronizeSource ? $rows : new Collection,
        ];
    }

    /**
     * @param  array<string,bool>  $expectedPaths
     * @return list<ArtifactDelete>
     */
    private function prunePhpFiles(
        string $targetRoot,
        string $locale,
        array $expectedPaths,
    ): array {
        $localePath = $this->paths->child($targetRoot, $locale);

        if (! File::isDirectory($localePath)) {
            return [];
        }

        $deletes = [];

        foreach (File::allFiles($localePath) as $file) {
            if ($file->getExtension() !== TranslationFormat::Php->value) {
                continue;
            }

            $path = $this->normalizedPath($file->getPathname());

            if (! isset($expectedPaths[$path])) {
                $deletes[] = [
                    'path' => $file->getPathname(),
                    'target_root' => $targetRoot,
                ];
            }
        }

        return $deletes;
    }

    /**
     * @param  Collection<int, TranslationEntry>  $rows
     */
    private function markSynchronized(Collection $rows): void
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($rows, $now): void {
            foreach ($rows as $row) {
                TranslationEntry::query()
                    ->whereKey($row->id)
                    ->lockForUpdate()
                    ->update([
                        'source_hash' => TranslationValueHash::make($row->value),
                        'sync_status' => 'synchronized',
                        'conflict_metadata' => null,
                        'last_imported_at' => $now,
                        'last_exported_at' => $now,
                        'revision' => DB::raw('revision + 1'),
                        'updated_at' => $now,
                    ]);
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $target
     */
    private function undotSet(array &$target, string $dotKey, ?string $value): void
    {
        $segments = explode('.', $dotKey);

        $this->setNestedValue($target, $segments, $value, $dotKey);
    }

    /**
     * Set a recursively nested PHP translation leaf.
     *
     * @param  array<array-key, mixed>  $target
     * @param  list<string>  $segments
     */
    private function setNestedValue(
        array &$target,
        array $segments,
        ?string $value,
        string $dotKey,
    ): void {
        $segment = $segments[0] ?? '';

        if ($segment === '') {
            throw new TranslationsException("Invalid empty translation key segment in [{$dotKey}].");
        }

        if (count($segments) === 1) {
            if (array_key_exists($segment, $target) && is_array($target[$segment])) {
                throw new TranslationsException(
                    "Translation key [{$dotKey}] conflicts with a nested PHP key.",
                );
            }

            $target[$segment] = $value;

            return;
        }

        $hasChild = array_key_exists($segment, $target);
        $child = $hasChild ? $target[$segment] : null;

        if ($hasChild && ! is_array($child)) {
            throw new TranslationsException(
                "Translation key [{$dotKey}] conflicts with a scalar PHP key.",
            );
        }

        $nested = is_array($child) ? $child : [];
        $this->setNestedValue($nested, array_slice($segments, 1), $value, $dotKey);
        $target[$segment] = $nested;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function renderPhpFile(array $payload): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".$this->renderPhpArray($payload).";\n";
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function renderPhpArray(array $payload, int $depth = 0): string
    {
        if ($payload === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth);
        $nextIndent = str_repeat('    ', $depth + 1);
        $lines = ['['];

        foreach ($payload as $key => $value) {
            $encodedValue = is_array($value)
                ? $this->renderPhpArray($value, $depth + 1)
                : var_export($value, true);

            $lines[] = sprintf(
                '%s%s => %s,',
                $nextIndent,
                var_export((string) $key, true),
                $encodedValue,
            );
        }

        $lines[] = $indent.']';

        return implode("\n", $lines);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function sortRecursively(array &$value): void
    {
        ksort($value);

        foreach ($value as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->sortRecursively($child);
            $value[$key] = $child;
        }
    }

    /**
     * @param  list<string>|null  $locales
     * @return list<string>|null
     */
    private function normalizeLocales(?array $locales): ?array
    {
        if ($locales === null || $locales === []) {
            return null;
        }

        $normalized = array_map(
            fn (string $locale): string => $this->paths->locale($locale),
            $locales,
        );

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{bool,bool}
     */
    private function formats(string $format): array
    {
        return match ($format) {
            TranslationFormat::Php->value => [true, false],
            TranslationFormat::Json->value => [false, true],
            'both' => [true, true],
            default => throw new InvalidTranslationInputException(
                "Invalid translation format [{$format}]. Expected php, json, or both.",
            ),
        };
    }

    private function normalizedPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
