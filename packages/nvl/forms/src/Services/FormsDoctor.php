<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Nvl\Forms\Contracts\FormEntryDeletionPolicy;
use Nvl\Forms\Contracts\FormEntryPrivacyPolicy;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Contracts\FormSpamDetector;
use Nvl\Forms\Data\FormsDoctorCheckData;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Throwable;

/**
 * Performs read-only production-readiness diagnostics for Forms.
 */
final readonly class FormsDoctor
{
    public function __construct(private Container $container) {}

    /**
     * @return list<FormsDoctorCheckData>
     */
    public function inspect(): array
    {
        return [
            ...$this->schemaChecks(),
            ...$this->bindingChecks(),
            ...$this->routeChecks(),
            ...$this->securityChecks(),
        ];
    }

    /**
     * @return list<FormsDoctorCheckData>
     */
    private function schemaChecks(): array
    {
        $requirements = [
            FormsTables::FORMS => ['id', 'handle', 'revision', 'status'],
            FormsTables::FORM_I18N => ['id', 'form_id', 'locale', 'name', 'content'],
            FormsTables::FORM_ENTRIES => [
                'id',
                'form_id',
                'submission_data',
                'idempotency_key',
                'payload_digest',
                'registration_fingerprint',
                'spam_score',
                'redacted_at',
                'anonymized_at',
            ],
            FormsTables::FORM_SUBMISSION_RECEIPTS => [
                'id',
                'form_id',
                'idempotency_key',
                'payload_digest',
                'registration_fingerprint',
                'state',
                'result_id',
            ],
            FormsTables::ALLOWED_ORIGINS => ['id', 'form_id', 'origin', 'is_active'],
            FormsTables::FORM_ANALYTICS => ['id', 'form_id', 'event_type'],
            FormsTables::FORM_RATE_LIMITS => ['id', 'form_id', 'ip_address'],
        ];
        $checks = [];

        try {
            foreach ($requirements as $table => $columns) {
                $exists = Schema::hasTable($table);
                $checks[] = $this->check(
                    "schema.table.{$table}",
                    $exists,
                    $exists
                        ? "Table [{$table}] is available."
                        : "Table [{$table}] is missing.",
                );

                if (! $exists) {
                    continue;
                }

                foreach ($columns as $column) {
                    $present = Schema::hasColumn($table, $column);
                    $checks[] = $this->check(
                        "schema.column.{$table}.{$column}",
                        $present,
                        $present
                            ? "Column [{$table}.{$column}] is available."
                            : "Column [{$table}.{$column}] is missing.",
                    );
                }

                $indexes = Schema::getIndexes($table);
                foreach ($this->requiredIndexes($table) as $requirement) {
                    $present = $this->hasIndex(
                        $indexes,
                        $requirement['columns'],
                        $requirement['unique'],
                    );
                    $label = implode(',', $requirement['columns']);
                    $checks[] = $this->check(
                        "schema.index.{$table}.{$label}",
                        $present,
                        $present
                            ? "Required index [{$table}:{$label}] is available."
                            : "Required index [{$table}:{$label}] is missing.",
                    );
                }

                $foreignKeys = Schema::getForeignKeys($table);
                foreach ($this->requiredForeignKeys($table) as $columns) {
                    $present = collect($foreignKeys)->contains(
                        static fn (mixed $foreignKey): bool => is_array($foreignKey)
                            && self::sameColumns(
                                is_array($foreignKey['columns'] ?? null)
                                    ? $foreignKey['columns']
                                    : [],
                                $columns,
                            ),
                    );
                    $label = implode(',', $columns);
                    $checks[] = $this->check(
                        "schema.foreign_key.{$table}.{$label}",
                        $present,
                        $present
                            ? "Required foreign key [{$table}:{$label}] is available."
                            : "Required foreign key [{$table}:{$label}] is missing.",
                    );
                }
            }

            if (Schema::hasColumn(FormsTables::FORM_ENTRIES, 'spam_score')) {
                $type = Schema::getColumnType(FormsTables::FORM_ENTRIES, 'spam_score', true);
                $numeric = str_contains(strtolower($type), 'int');
                $checks[] = $this->check(
                    'schema.type.form_entries.spam_score',
                    $numeric,
                    $numeric
                        ? 'Spam score uses a numeric database type.'
                        : "Spam score must use a numeric database type; found [{$type}].",
                );
            }
        } catch (Throwable $throwable) {
            $checks[] = $this->check(
                'schema.connection',
                false,
                'Database inspection failed: '.mb_substr($throwable->getMessage(), 0, 500),
            );
        }

        return $checks;
    }

    /**
     * @return list<FormsDoctorCheckData>
     */
    private function bindingChecks(): array
    {
        $bindings = [
            'deletion_policy' => FormEntryDeletionPolicy::class,
            'privacy_policy' => FormEntryPrivacyPolicy::class,
            'rate_limiter' => FormRateLimiter::class,
            'spam_detector' => FormSpamDetector::class,
        ];

        return array_map(function (string $key, string $contract): FormsDoctorCheckData {
            $bound = $this->container->bound($contract);

            return $this->check(
                "binding.{$key}",
                $bound,
                $bound
                    ? "Required binding [{$contract}] is available."
                    : "Required binding [{$contract}] is missing.",
            );
        }, array_keys($bindings), array_values($bindings));
    }

    /**
     * @return list<FormsDoctorCheckData>
     */
    private function routeChecks(): array
    {
        $managementEnabled = (bool) config('forms.routes.management.enabled', false);
        $managementMiddleware = $this->middleware('management');
        $gate = config('forms.authorization.gate');
        $gateReady = is_string($gate) && $gate !== '' && Gate::has($gate);
        $hasAuthentication = collect($managementMiddleware)->contains(
            static fn (string $middleware): bool => preg_match('/(?:^|\\\\|:)auth(?:$|:)/i', $middleware) === 1
                || str_contains(strtolower($middleware), 'sanctum')
                || str_contains(strtolower($middleware), 'passport'),
        );

        $publicEnabled = (bool) config('forms.routes.public.enabled', false);
        $publicMiddleware = $this->middleware('public');
        $hasThrottle = collect($publicMiddleware)->contains(
            static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:'),
        );

        return [
            $this->check(
                'routes.management.authentication',
                ! $managementEnabled || $hasAuthentication,
                ! $managementEnabled
                    ? 'Management routes are disabled.'
                    : ($hasAuthentication
                        ? 'Management routes have explicit authentication middleware.'
                        : 'Management routes require explicit authentication middleware.'),
            ),
            $this->check(
                'authorization.management',
                ! $managementEnabled || $gateReady,
                ! $managementEnabled
                    ? 'Management routes are disabled.'
                    : ($gateReady
                        ? "Management gate [{$gate}] is registered."
                        : 'Management routes require a configured and registered authorization gate.'),
            ),
            $this->check(
                'routes.public.throttle',
                ! $publicEnabled || $hasThrottle,
                ! $publicEnabled
                    ? 'Public routes are disabled.'
                    : ($hasThrottle
                        ? 'Public routes have explicit throttle middleware.'
                        : 'Public routes require explicit throttle middleware.'),
            ),
        ];
    }

    /**
     * @return list<FormsDoctorCheckData>
     */
    private function securityChecks(): array
    {
        $applicationKey = config('app.key');
        $hasApplicationKey = is_string($applicationKey) && trim($applicationKey) !== '';

        return [
            $this->check(
                'security.application_key',
                $hasApplicationKey,
                $hasApplicationKey
                    ? 'Application key is available for signing public form tokens.'
                    : 'APP_KEY is required to sign public form tokens.',
            ),
        ];
    }

    /**
     * @return list<array{columns:list<string>,unique:bool}>
     */
    private function requiredIndexes(string $table): array
    {
        return match ($table) {
            FormsTables::FORMS => [
                ['columns' => ['handle'], 'unique' => true],
                ['columns' => ['status'], 'unique' => false],
            ],
            FormsTables::FORM_I18N => [
                ['columns' => ['form_id', 'locale'], 'unique' => true],
            ],
            FormsTables::FORM_ENTRIES => [
                ['columns' => ['form_id', 'created_at'], 'unique' => false],
                ['columns' => ['form_id', 'idempotency_key'], 'unique' => true],
                ['columns' => ['form_id', 'registration_fingerprint'], 'unique' => true],
            ],
            FormsTables::FORM_SUBMISSION_RECEIPTS => [
                ['columns' => ['form_id', 'idempotency_key'], 'unique' => true],
                ['columns' => ['form_id', 'registration_fingerprint'], 'unique' => true],
                ['columns' => ['state', 'updated_at'], 'unique' => false],
            ],
            FormsTables::ALLOWED_ORIGINS => [
                ['columns' => ['form_id', 'origin'], 'unique' => true],
                ['columns' => ['form_id', 'is_active'], 'unique' => false],
            ],
            FormsTables::FORM_ANALYTICS => [
                ['columns' => ['form_id', 'event_type'], 'unique' => false],
                ['columns' => ['form_id', 'created_at'], 'unique' => false],
            ],
            FormsTables::FORM_RATE_LIMITS => [
                ['columns' => ['form_id', 'ip_address'], 'unique' => true],
            ],
            default => [],
        };
    }

    /**
     * @return list<list<string>>
     */
    private function requiredForeignKeys(string $table): array
    {
        return match ($table) {
            FormsTables::FORM_I18N,
            FormsTables::FORM_ENTRIES,
            FormsTables::FORM_SUBMISSION_RECEIPTS,
            FormsTables::ALLOWED_ORIGINS,
            FormsTables::FORM_ANALYTICS,
            FormsTables::FORM_RATE_LIMITS => [['form_id']],
            default => [],
        };
    }

    /**
     * @param  array<mixed>  $indexes
     * @param  list<string>  $columns
     */
    private function hasIndex(array $indexes, array $columns, bool $unique): bool
    {
        return collect($indexes)->contains(
            static fn (mixed $index): bool => is_array($index)
                && (bool) ($index['unique'] ?? false) === $unique
                && self::sameColumns(
                    is_array($index['columns'] ?? null) ? $index['columns'] : [],
                    $columns,
                ),
        );
    }

    /**
     * @param  array<mixed>  $actual
     * @param  list<string>  $expected
     */
    private static function sameColumns(array $actual, array $expected): bool
    {
        $actual = array_values(array_filter($actual, 'is_string'));

        return $actual === $expected;
    }

    /**
     * @return list<string>
     */
    private function middleware(string $surface): array
    {
        return array_values(array_filter(
            (array) config("forms.routes.{$surface}.middleware", []),
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    private function check(string $key, bool $passed, string $message): FormsDoctorCheckData
    {
        return new FormsDoctorCheckData(
            key: $key,
            severity: 'error',
            passed: $passed,
            message: $message,
        );
    }
}
