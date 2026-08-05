<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Translations\Enums\TranslationConflictStrategy;
use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationConflictException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Support\TranslationConfiguration;
use Nvl\Translations\Support\TranslationIdentity;
use Nvl\Translations\Support\TranslationScope;
use Nvl\Translations\Support\TranslationValueHash;
use ReflectionObject;
use stdClass;
use Throwable;

/**
 * Synchronizes configured PHP and JSON translation files into editable database rows.
 *
 * This stable synchronization boundary owns its database transaction so direct
 * programmatic callers receive the same all-or-nothing guarantees as Actions.
 *
 * @phpstan-type TranslationRow array{
 *     id:string,
 *     identity_hash:string,
 *     scope_type:string,
 *     scope_name:string,
 *     locale:string,
 *     format:string,
 *     group:string,
 *     key:string,
 *     value:string|null,
 *     source_hash:string,
 *     is_missing:bool,
 *     last_imported_at:CarbonImmutable,
 *     revision:int,
 *     sync_status:string,
 *     conflict_metadata:string|null,
 *     created_at:CarbonImmutable,
 *     updated_at:CarbonImmutable
 * }
 * @phpstan-type ParsedScope array{
 *     files:int,
 *     rows:array<string, TranslationRow>,
 *     warnings:list<string>
 * }
 */
final class TranslationImportService
{
    public function __construct(
        private readonly TranslationScopeResolver $scopeResolver,
        private readonly TranslationPathGuard $paths,
    ) {}

    /**
     * Import translation files for the selected scopes and formats.
     *
     * @param  list<string>  $scopeTokens
     * @return array{
     *     scopes:int,
     *     files:int,
     *     entries:int,
     *     created:int,
     *     updated:int,
     *     preserved:int,
     *     conflicts:int,
     *     missing:int,
     *     warnings:list<string>
     * }
     */
    public function execute(
        array $scopeTokens = [],
        string $format = 'both',
        bool $dryRun = false,
    ): array {
        [$includePhp, $includeJson] = $this->formats($format);
        $scopes = $this->scopeResolver->resolveScopes($scopeTokens);
        $summary = [
            'scopes' => count($scopes),
            'files' => 0,
            'entries' => 0,
            'created' => 0,
            'updated' => 0,
            'preserved' => 0,
            'conflicts' => 0,
            'missing' => 0,
            'warnings' => [],
        ];
        $parsedScopes = [];

        foreach ($scopes as $scope) {
            if (! File::isDirectory($scope->path)) {
                $summary['warnings'][] = "Translation source directory [{$scope->path}] does not exist.";

                continue;
            }

            $parsed = $this->readScope($scope, $includePhp, $includeJson);
            $summary['files'] += $parsed['files'];
            $summary['entries'] += count($parsed['rows']);
            $summary['warnings'] = [...$summary['warnings'], ...$parsed['warnings']];
            $parsedScopes[] = ['scope' => $scope, 'parsed' => $parsed];
        }

        $readWarnings = array_values(array_filter(
            $parsedScopes,
            static fn (array $item): bool => $item['parsed']['warnings'] !== [],
        ));

        if ($readWarnings !== [] && (bool) config('translations.import.fail_on_error', true)) {
            throw new TranslationsException(
                'Translation import stopped before database synchronization: '.
                implode(' ', array_merge(...array_map(
                    static fn (array $item): array => $item['parsed']['warnings'],
                    $readWarnings,
                ))),
            );
        }

        DB::transaction(function () use (
            $parsedScopes,
            $includePhp,
            $includeJson,
            $dryRun,
            &$summary,
        ): void {
            foreach ($parsedScopes as $item) {
                /** @var TranslationScope $scope */
                $scope = $item['scope'];
                $parsed = $item['parsed'];
                $sync = $this->syncScope(
                    $scope,
                    $parsed['rows'],
                    $parsed['warnings'] === [],
                    $includePhp,
                    $includeJson,
                    $dryRun,
                );

                foreach (['created', 'updated', 'preserved', 'conflicts', 'missing'] as $counter) {
                    $summary[$counter] += $sync[$counter];
                }
            }
        });

        return $summary;
    }

    /**
     * @return array{
     *     files:int,
     *     rows:array<string, TranslationRow>,
     *     warnings:list<string>
     * }
     */
    private function readScope(TranslationScope $scope, bool $includePhp, bool $includeJson): array
    {
        $result = ['files' => 0, 'rows' => [], 'warnings' => []];
        $now = CarbonImmutable::now();

        if ($includePhp) {
            $this->readPhpFiles($scope, $now, $result);
        }

        if ($includeJson) {
            $this->readJsonFiles($scope, $now, $result);
        }

        return $result;
    }

    /**
     * @param  ParsedScope  $result
     */
    private function readPhpFiles(TranslationScope $scope, CarbonImmutable $now, array &$result): void
    {
        foreach (File::directories($scope->path) as $localeDirectory) {
            if (! is_string($localeDirectory)) {
                continue;
            }

            if ($scope->type === TranslationScopeType::App
                && basename($localeDirectory) === 'vendor') {
                continue;
            }

            $localeName = basename($localeDirectory);

            try {
                $locale = $this->paths->locale($localeName);
            } catch (TranslationsException) {
                continue;
            }

            try {
                $safeLocaleDirectory = $this->paths->child($scope->path, $localeName);
            } catch (TranslationsException $exception) {
                $result['warnings'][] = $exception->getMessage();

                continue;
            }

            foreach (File::allFiles($safeLocaleDirectory) as $phpFile) {
                if ($phpFile->getExtension() !== TranslationFormat::Php->value) {
                    continue;
                }

                $normalizedPhpPath = str_replace('\\', '/', $phpFile->getPathname());
                $normalizedLocaleDirectory = rtrim(
                    str_replace('\\', '/', $safeLocaleDirectory),
                    '/',
                );
                $relativePath = Str::after(
                    $normalizedPhpPath,
                    $normalizedLocaleDirectory.'/',
                );
                $group = Str::of($relativePath)->replaceEnd('.php', '')->trim('/')->value();

                try {
                    $group = $this->paths->group($group);
                    $phpPath = $this->paths->child(
                        $scope->path,
                        $localeName,
                        $relativePath,
                    );
                } catch (TranslationsException $exception) {
                    $result['warnings'][] = $exception->getMessage();

                    continue;
                }

                try {
                    $payload = $this->loadPhpPayload($phpPath);
                } catch (Throwable $exception) {
                    $result['warnings'][] = sprintf('Failed to read %s: %s', $phpPath, $exception->getMessage());

                    continue;
                }

                if (! is_array($payload)) {
                    $result['warnings'][] = sprintf('Skipping %s because it does not return an array.', $phpPath);

                    continue;
                }

                foreach ($this->flattenPhpPayload($payload, $phpPath, $result['warnings']) as $key => $value) {
                    $row = $this->buildRow(
                        $scope,
                        $locale,
                        TranslationFormat::Php,
                        $group,
                        $key,
                        $value,
                        $now,
                    );
                    $result['rows'][$this->rowIdentity($row)] = $row;
                }

                $result['files']++;
            }
        }
    }

    /**
     * @param  ParsedScope  $result
     */
    private function readJsonFiles(TranslationScope $scope, CarbonImmutable $now, array &$result): void
    {
        foreach (File::files($scope->path) as $jsonFile) {
            if ($jsonFile->getExtension() !== TranslationFormat::Json->value) {
                continue;
            }

            try {
                $locale = $this->paths->locale($jsonFile->getFilenameWithoutExtension());
                $jsonPath = $this->paths->child($scope->path, $jsonFile->getFilename());
                $decoded = json_decode((string) File::get($jsonPath), false, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $result['warnings'][] = sprintf(
                    'Failed to read JSON translation file %s: %s',
                    $jsonFile->getPathname(),
                    $exception->getMessage(),
                );

                continue;
            }

            if (! $decoded instanceof stdClass) {
                $result['warnings'][] = sprintf(
                    'Skipping %s because it must contain a JSON object.',
                    $jsonPath,
                );

                continue;
            }

            foreach ((new ReflectionObject($decoded))->getProperties() as $property) {
                $key = $property->getName();
                $value = $property->getValue($decoded);

                if (! mb_check_encoding($key, 'UTF-8')) {
                    $result['warnings'][] = "Skipping invalid UTF-8 translation key in [{$jsonPath}].";

                    continue;
                }

                $normalized = $this->normalizeValue(
                    $value,
                    $jsonPath,
                    $key,
                    $result['warnings'],
                );

                if ($normalized === false) {
                    continue;
                }

                $row = $this->buildRow(
                    $scope,
                    $locale,
                    TranslationFormat::Json,
                    '*',
                    $key,
                    $normalized,
                    $now,
                );
                $result['rows'][$this->rowIdentity($row)] = $row;
            }

            $result['files']++;
        }
    }

    /**
     * Load one PHP catalog while preventing accidental output from corrupting callers.
     */
    private function loadPhpPayload(string $path): mixed
    {
        $bufferLevel = ob_get_level();

        if (! ob_start()) {
            throw new TranslationsException("Failed to buffer PHP translation file [{$path}].");
        }

        try {
            $payload = include $path;

            if (ob_get_level() !== $bufferLevel + 1) {
                throw new TranslationsException(
                    "PHP translation file [{$path}] altered output buffering.",
                );
            }

            $output = ob_get_clean();

            if ($output !== '') {
                throw new TranslationsException("PHP translation file [{$path}] emitted output.");
            }

            return $payload;
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    /**
     * Flatten a PHP catalog without silently conflating literal dots with nesting.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $warnings
     * @param  list<string>  $parents
     * @return array<string, string|null>
     */
    private function flattenPhpPayload(
        array $payload,
        string $path,
        array &$warnings,
        array $parents = [],
    ): array {
        $flattened = [];

        foreach ($payload as $segment => $value) {
            $keySegment = (string) $segment;

            if ($keySegment === '' || str_contains($keySegment, '.')) {
                $warnings[] = "Skipping ambiguous PHP translation key segment [{$keySegment}] in [{$path}].";

                continue;
            }

            if (! mb_check_encoding($keySegment, 'UTF-8')) {
                $warnings[] = "Skipping invalid UTF-8 translation key in [{$path}].";

                continue;
            }

            $segments = [...$parents, $keySegment];

            if (is_array($value)) {
                foreach ($this->flattenPhpPayload($value, $path, $warnings, $segments) as $key => $nestedValue) {
                    $flattened[$key] = $nestedValue;
                }

                continue;
            }

            $key = implode('.', $segments);
            $normalized = $this->normalizeValue($value, $path, $key, $warnings);

            if ($normalized !== false) {
                $flattened[$key] = $normalized;
            }
        }

        return $flattened;
    }

    /**
     * @param  array<string, TranslationRow>  $rows
     * @return array{created:int,updated:int,preserved:int,conflicts:int,missing:int}
     */
    private function syncScope(
        TranslationScope $scope,
        array $rows,
        bool $completeRead,
        bool $includePhp,
        bool $includeJson,
        bool $dryRun,
    ): array {
        return DB::transaction(function () use (
            $scope,
            $rows,
            $completeRead,
            $includePhp,
            $includeJson,
            $dryRun,
        ): array {
            $existingRows = TranslationEntry::query()
                ->where('scope_type', $scope->type->value)
                ->where('scope_name', $scope->name)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (TranslationEntry $entry): string => $entry->identity_hash);

            $strategy = TranslationConflictStrategy::tryFrom(TranslationConfiguration::string(
                'translations.import.conflict_strategy',
                TranslationConflictStrategy::Fail->value,
            )) ?? TranslationConflictStrategy::Fail;

            $created = 0;
            $updated = 0;
            $preserved = 0;
            $conflicts = 0;
            $missing = 0;
            $upserts = [];

            foreach ($rows as $identity => $row) {
                $existing = $existingRows->get($identity);

                if (! $existing instanceof TranslationEntry) {
                    $created++;
                    $upserts[] = $row;

                    continue;
                }

                $databaseChanged = $existing->source_hash !== null
                    && ! hash_equals($existing->source_hash, TranslationValueHash::make($existing->value));
                $fileChanged = $existing->source_hash !== null
                    && ! hash_equals($existing->source_hash, $row['source_hash']);
                $valuesConverged = $existing->value === $row['value'];
                $row['revision'] = $existing->revision;
                $row['sync_status'] = 'synchronized';
                $row['conflict_metadata'] = null;

                if ($databaseChanged && $fileChanged && ! $valuesConverged) {
                    $conflicts++;
                    $row['conflict_metadata'] = json_encode([
                        'database_hash' => TranslationValueHash::make($existing->value),
                        'file_hash' => $row['source_hash'],
                        'detected_at' => CarbonImmutable::now()->toIso8601String(),
                        'strategy' => $strategy->value,
                    ], JSON_THROW_ON_ERROR);

                    if ($strategy === TranslationConflictStrategy::Fail) {
                        throw TranslationConflictException::forIdentity($scope->token(), $identity);
                    }

                    if ($strategy === TranslationConflictStrategy::PreferDatabase) {
                        $row['value'] = $existing->value;
                        $row['sync_status'] = 'conflict';
                        $preserved++;
                    } else {
                        $row['sync_status'] = 'conflict';
                        $updated++;
                    }

                    $row['revision'] = $existing->revision + 1;
                } elseif ($valuesConverged && ($databaseChanged || $fileChanged)) {
                    $updated++;
                    $row['revision'] = $existing->revision + 1;
                } elseif ($databaseChanged) {
                    $row['value'] = $existing->value;
                    $row['sync_status'] = $existing->sync_status === TranslationSyncStatus::Conflict
                        ? TranslationSyncStatus::Conflict->value
                        : TranslationSyncStatus::Edited->value;
                    $row['conflict_metadata'] = $existing->conflict_metadata === null
                        ? null
                        : json_encode($existing->conflict_metadata, JSON_THROW_ON_ERROR);
                    $preserved++;
                } elseif ($fileChanged
                    || $existing->value !== $row['value']
                    || $existing->is_missing) {
                    $updated++;
                    $row['revision'] = $existing->revision + 1;
                }

                $upserts[] = $row;
            }

            if (! $dryRun) {
                foreach (array_chunk($upserts, 500) as $chunk) {
                    TranslationEntry::query()->upsert(
                        $chunk,
                        ['identity_hash'],
                        [
                            'scope_type',
                            'scope_name',
                            'locale',
                            'format',
                            'group',
                            'key',
                            'value',
                            'source_hash',
                            'is_missing',
                            'last_imported_at',
                            'updated_at',
                            'revision',
                            'sync_status',
                            'conflict_metadata',
                        ],
                    );
                }
            }

            if ($completeRead) {
                $formats = [];
                if ($includePhp) {
                    $formats[] = TranslationFormat::Php->value;
                }
                if ($includeJson) {
                    $formats[] = TranslationFormat::Json->value;
                }

                $seenIdentities = array_fill_keys(array_keys($rows), true);

                foreach ($existingRows as $entry) {
                    if (! in_array($entry->format, $formats, true)) {
                        continue;
                    }

                    if (isset($seenIdentities[$this->entryIdentity($entry)])) {
                        continue;
                    }

                    if ($entry->is_missing) {
                        continue;
                    }

                    $missing++;

                    if (! $dryRun) {
                        $entry->update([
                            'is_missing' => true,
                            'sync_status' => 'missing',
                        ]);
                    }
                }
            }

            return compact('created', 'updated', 'preserved', 'conflicts', 'missing');
        });
    }

    /**
     * @param  list<string>  $warnings
     */
    private function normalizeValue(mixed $value, string $path, string $key, array &$warnings): string|null|false
    {
        if ($value === null || is_string($value)) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $warnings[] = "Skipping invalid UTF-8 translation [{$key}] in [{$path}].";

                return false;
            }

            return $value;
        }

        if ($value === []) {
            return false;
        }

        $warnings[] = "Skipping non-string translation [{$key}] in [{$path}].";

        return false;
    }

    /**
     * @return TranslationRow
     */
    private function buildRow(
        TranslationScope $scope,
        string $locale,
        TranslationFormat $format,
        string $group,
        string $key,
        ?string $value,
        CarbonImmutable $now,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'identity_hash' => TranslationIdentity::entry(
                $scope->type->value,
                $scope->name,
                $locale,
                $format->value,
                $group,
                $key,
            ),
            'scope_type' => $scope->type->value,
            'scope_name' => $scope->name,
            'locale' => $locale,
            'format' => $format->value,
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'source_hash' => TranslationValueHash::make($value),
            'is_missing' => false,
            'last_imported_at' => $now,
            'revision' => 1,
            'sync_status' => 'synchronized',
            'conflict_metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  TranslationRow  $row
     */
    private function rowIdentity(array $row): string
    {
        return $row['identity_hash'];
    }

    private function entryIdentity(TranslationEntry $entry): string
    {
        return $entry->identity_hash;
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
}
