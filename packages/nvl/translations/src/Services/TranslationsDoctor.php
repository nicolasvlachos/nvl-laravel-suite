<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Data\TranslationsDoctorCheckData;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Support\TranslationConfiguration;
use RuntimeException;
use Throwable;

/**
 * Inspects workspace schema, profiles, routes, and bindings without mutation.
 */
final readonly class TranslationsDoctor
{
    public function __construct(
        private TranslationsAuthorization $authorization,
        private TranslationScopeResolver $scopes,
        private TranslationPathGuard $paths,
        private TranslationScanService $scanner,
    ) {}

    /**
     * @return list<TranslationsDoctorCheckData>
     */
    public function inspect(): array
    {
        return [
            ...$this->schemaChecks(),
            $this->scopeCheck(),
            $this->exportTargetsCheck(),
            $this->backupCheck(),
            $this->lockCheck(),
            $this->scannerCheck(),
            $this->authorizationCheck(),
            $this->routeCheck(),
        ];
    }

    /**
     * @return list<TranslationsDoctorCheckData>
     */
    private function schemaChecks(): array
    {
        $tables = [
            TranslationsTables::Entries => [
                'columns' => [
                    'id', 'identity_hash', 'scope_type', 'scope_name', 'locale', 'format', 'group',
                    'key', 'value', 'source_hash', 'is_missing', 'revision', 'sync_status',
                    'conflict_metadata', 'last_imported_at', 'last_exported_at',
                    'created_at', 'updated_at',
                ],
                'identity' => true,
            ],
            TranslationsTables::ScanRuns => [
                'columns' => ['id', 'scanned_at', 'files', 'hits', 'created_at', 'updated_at'],
                'identity' => false,
            ],
            TranslationsTables::Usages => [
                'columns' => [
                    'id', 'identity_hash', 'scan_id', 'scope_type', 'scope_name', 'format',
                    'full_key', 'file_path', 'line', 'last_seen_at', 'created_at', 'updated_at',
                ],
                'identity' => true,
            ],
        ];
        $checks = [];

        foreach ($tables as $table => $requirements) {
            $exists = Schema::hasTable($table);
            $checks[] = new TranslationsDoctorCheckData(
                key: "schema.table.{$table}",
                severity: 'error',
                passed: $exists,
                message: $exists ? "Table [{$table}] exists." : "Table [{$table}] is missing.",
            );

            if (! $exists) {
                continue;
            }

            $missing = array_values(array_filter(
                $requirements['columns'],
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ));
            $checks[] = new TranslationsDoctorCheckData(
                key: "schema.columns.{$table}",
                severity: 'error',
                passed: $missing === [],
                message: $missing === []
                    ? "Required columns exist on [{$table}]."
                    : 'Missing columns: '.implode(', ', $missing).'.',
            );

            if (! $requirements['identity']) {
                continue;
            }

            $hasIdentityIndex = false;
            foreach (Schema::getIndexes($table) as $index) {
                if (! is_array($index)) {
                    continue;
                }

                if (($index['unique'] ?? false) === true
                    && ($index['columns'] ?? []) === ['identity_hash']) {
                    $hasIdentityIndex = true;

                    break;
                }
            }
            $checks[] = new TranslationsDoctorCheckData(
                key: "schema.identity.{$table}",
                severity: 'error',
                passed: $hasIdentityIndex,
                message: $hasIdentityIndex
                    ? "Case-sensitive identity uniqueness exists on [{$table}]."
                    : "A unique identity_hash index is missing from [{$table}].",
            );
        }

        return $checks;
    }

    private function scopeCheck(): TranslationsDoctorCheckData
    {
        try {
            $count = count($this->scopes->discoverScopes());

            return new TranslationsDoctorCheckData(
                key: 'profiles.sources',
                severity: 'error',
                passed: $count > 0,
                message: "{$count} source profile(s) are valid.",
            );
        } catch (Throwable $exception) {
            return new TranslationsDoctorCheckData(
                key: 'profiles.sources',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    private function backupCheck(): TranslationsDoctorCheckData
    {
        if (! (bool) config('translations.backup.enabled', true)) {
            return new TranslationsDoctorCheckData(
                key: 'files.backup_directory',
                severity: 'warning',
                passed: false,
                message: 'Translation file backups are disabled.',
            );
        }

        try {
            $directory = config('translations.backup.directory');

            if (! is_string($directory)) {
                throw new RuntimeException('The backup directory must be a string.');
            }

            $this->paths->root($directory);

            return new TranslationsDoctorCheckData(
                key: 'files.backup_directory',
                severity: 'error',
                passed: true,
                message: 'The configured backup directory is safe.',
            );
        } catch (Throwable $exception) {
            return new TranslationsDoctorCheckData(
                key: 'files.backup_directory',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    private function authorizationCheck(): TranslationsDoctorCheckData
    {
        $configuredAbility = config('translations.authorization.ability');
        $configuredName = is_string($configuredAbility) ? trim($configuredAbility) : '';
        $configured = $configuredName !== '' && Gate::has($configuredName);
        $usesCustomBoundary = ! $this->authorization instanceof ConfiguredTranslationsAuthorization;
        $ready = ! (bool) config('translations.routes.enabled', false)
            || $configured
            || $usesCustomBoundary;

        return new TranslationsDoctorCheckData(
            key: 'binding.authorization',
            severity: 'error',
            passed: $ready,
            message: match (true) {
                ! (bool) config('translations.routes.enabled', false) => 'Authorization is bound; management routes are disabled.',
                $configured => 'The configured Gate ability protects management routes.',
                $usesCustomBoundary => 'A custom TranslationsAuthorization implementation protects management routes.',
                $configuredName !== '' => "Configured Gate ability [{$configuredName}] is not registered.",
                default => 'Management routes require a configured Gate ability or custom authorization binding.',
            },
        );
    }

    private function scannerCheck(): TranslationsDoctorCheckData
    {
        try {
            $this->scanner->validateConfiguration();

            return new TranslationsDoctorCheckData(
                key: 'scan.configuration',
                severity: 'error',
                passed: true,
                message: 'Scanner roots, extensions, and patterns are valid.',
            );
        } catch (Throwable $exception) {
            return new TranslationsDoctorCheckData(
                key: 'scan.configuration',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    private function exportTargetsCheck(): TranslationsDoctorCheckData
    {
        try {
            $configured = config('translations.export_targets', []);

            if (! is_array($configured)) {
                throw new RuntimeException('translations.export_targets must be an associative target map.');
            }

            $scopes = $this->scopes->discoverScopes();
            $this->scopes->resolveExportPaths($scopes, 'source');

            foreach (array_keys($configured) as $target) {
                if (! is_string($target)) {
                    throw new RuntimeException('Every translations.export_targets key must be a string.');
                }

                if ($target === 'source') {
                    if (($configured[$target] ?? null) !== []) {
                        throw new RuntimeException('The reserved source export target must use an empty map.');
                    }

                    continue;
                }

                $this->scopes->resolveExportPaths($scopes, $target);
            }

            return new TranslationsDoctorCheckData(
                key: 'profiles.export_targets',
                severity: 'error',
                passed: true,
                message: 'Configured export targets are distinct from sources and backups.',
            );
        } catch (Throwable $exception) {
            return new TranslationsDoctorCheckData(
                key: 'profiles.export_targets',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    private function lockCheck(): TranslationsDoctorCheckData
    {
        try {
            TranslationConfiguration::positiveInteger('translations.lock.seconds', 300);
            TranslationConfiguration::nonNegativeInteger('translations.lock.wait_seconds', 0);
            $configuredStore = config('translations.lock.store');
            $store = is_string($configuredStore) && trim($configuredStore) !== ''
                ? trim($configuredStore)
                : null;
            $cacheStore = Cache::store($store)->getStore();
            $supportsLocks = $cacheStore instanceof LockProvider;
            $driver = $cacheStore::class;

            return new TranslationsDoctorCheckData(
                key: 'lock.workspace',
                severity: 'error',
                passed: $supportsLocks,
                message: $supportsLocks
                    ? "Workspace locking is supported by [{$driver}]."
                    : "Cache store [{$driver}] does not support atomic locks.",
            );
        } catch (Throwable $exception) {
            return new TranslationsDoctorCheckData(
                key: 'lock.workspace',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    private function routeCheck(): TranslationsDoctorCheckData
    {
        if (! (bool) config('translations.routes.enabled', false)) {
            return new TranslationsDoctorCheckData(
                key: 'routes.management',
                severity: 'info',
                passed: true,
                message: 'Management routes are disabled.',
            );
        }

        $middleware = array_values(array_filter(
            (array) config('translations.routes.management_middleware', []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
        $requiredRoutes = [
            'nvl.translations.management.index',
            'nvl.translations.management.import',
            'nvl.translations.management.export',
            'nvl.translations.management.scan',
            'nvl.translations.management.entries.update',
        ];
        $missingRoutes = array_values(array_filter(
            $requiredRoutes,
            static fn (string $route): bool => ! Route::has($route),
        ));

        return new TranslationsDoctorCheckData(
            key: 'routes.management',
            severity: 'error',
            passed: $middleware !== [] && $missingRoutes === [],
            message: match (true) {
                $middleware === [] => 'Management routes are enabled without management middleware.',
                $missingRoutes !== [] => 'Missing management routes: '.implode(', ', $missingRoutes).'.',
                default => 'Management routes are enabled with explicit middleware.',
            },
        );
    }
}
