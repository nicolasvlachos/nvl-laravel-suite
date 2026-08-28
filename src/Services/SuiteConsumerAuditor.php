<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Nvl\Suite\Services\ConsumerAudit\ComposerSourceRootLocator;
use Nvl\Suite\Services\ConsumerAudit\PhpConsumerBoundaryScanner;
use Nvl\Suite\Services\ConsumerAudit\SuiteRuntimeConsumerScanner;
use Nvl\Suite\Support\ConsumerAuditFinding;
use Nvl\Suite\Support\SuiteModuleCatalog;
use ReflectionClass;

/**
 * Coordinates static and runtime consumer-readiness checks.
 */
final readonly class SuiteConsumerAuditor
{
    public function __construct(
        private ComposerSourceRootLocator $sourceRoots,
        private PhpConsumerBoundaryScanner $boundaryScanner,
        private SuiteRuntimeConsumerScanner $runtimeScanner,
        private SuiteModuleCatalog $catalog,
        private Repository $configuration,
        private Application $application,
    ) {}

    /**
     * Audit one consumer application without changing it.
     *
     * @return list<ConsumerAuditFinding>
     */
    public function audit(string $basePath): array
    {
        $suppressions = $this->suppressions();
        $roots = $this->sourceRoots->locate($basePath);
        $findings = [
            ...$this->boundaryScanner->scan(
                basePath: $basePath,
                roots: $roots,
                namespaceModules: $this->namespaceModules(),
                tableOwners: $this->tableOwners(),
                enabledPackages: $this->catalog->effectiveModules(),
            ),
            ...($this->runtimeChecked($basePath) ? $this->runtimeScanner->scan() : []),
        ];

        $findings = array_values(array_filter(
            $findings,
            static fn (ConsumerAuditFinding $finding): bool => ! self::suppressed($finding, $suppressions),
        ));

        usort($findings, static fn (ConsumerAuditFinding $left, ConsumerAuditFinding $right): int => [
            $left->path,
            $left->line ?? 0,
            $left->code,
            $left->symbol,
        ] <=> [
            $right->path,
            $right->line ?? 0,
            $right->code,
            $right->symbol,
        ]);

        return $findings;
    }

    /**
     * Return whether the target is the booted application whose runtime can be inspected.
     */
    public function runtimeChecked(string $basePath): bool
    {
        $target = realpath($basePath);
        $application = realpath($this->application->basePath());

        return $target !== false && $application !== false && $target === $application;
    }

    /**
     * @return array<string, string>
     */
    private function namespaceModules(): array
    {
        $modules = [];

        foreach ($this->catalog->modules() as $module => $definition) {
            $segments = explode('\\', $definition['provider']);
            $namespace = $segments[1] ?? null;

            if (is_string($namespace) && $namespace !== 'Suite') {
                $modules[strtolower($namespace)] = $module;
            }
        }

        return $modules;
    }

    /**
     * @return array<string, string>
     */
    private function tableOwners(): array
    {
        $owners = [];

        foreach ($this->catalog->tableDefinitions() as $package => $definition) {
            foreach ((new ReflectionClass($definition))->getConstants() as $table) {
                if (is_string($table) && $table !== '') {
                    $owners[$table] = $package;

                    if (method_exists($definition, 'get')) {
                        $configured = $definition::get($table);

                        if (is_string($configured) && $configured !== '') {
                            $owners[$configured] = $package;
                        }
                    }
                }
            }
        }

        return $owners;
    }

    /**
     * @return list<array{code: string, path: string, symbol: string, reason: string}>
     */
    private function suppressions(): array
    {
        $configured = $this->configuration->get('nvl-suite.consumer_audit.suppressions', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('nvl-suite.consumer_audit.suppressions must be an array.');
        }

        $suppressions = [];

        foreach ($configured as $suppression) {
            if (! is_array($suppression)) {
                throw new InvalidArgumentException('Every consumer audit suppression must be an object.');
            }

            $normalized = $this->normalizeSuppression($suppression);

            if (! in_array($normalized['code'], ConsumerAuditFinding::CODES, true)) {
                throw new InvalidArgumentException('Consumer audit suppressions must use a known finding code.');
            }

            if (str_starts_with($normalized['path'], '/')
                || preg_match('/^[A-Za-z]:[\\\\\/]/', $normalized['path']) === 1
                || preg_match('/(^|[\\\\\/])\.\.(?:[\\\\\/]|$)/', $normalized['path']) === 1
                || strpbrk($normalized['path'].$normalized['symbol'], '*?[]{}') !== false
                || $this->looksLikeRegularExpression($normalized['path'])
                || $this->looksLikeRegularExpression($normalized['symbol'])) {
                throw new InvalidArgumentException('Consumer audit suppressions must use exact relative paths and symbols.');
            }

            $suppressions[] = $normalized;
        }

        return $suppressions;
    }

    /**
     * @param  array<mixed, mixed>  $suppression
     * @return array{code: string, path: string, symbol: string, reason: string}
     */
    private function normalizeSuppression(array $suppression): array
    {
        return [
            'code' => $this->suppressionField($suppression, 'code'),
            'path' => $this->suppressionField($suppression, 'path'),
            'symbol' => $this->suppressionField($suppression, 'symbol'),
            'reason' => $this->suppressionField($suppression, 'reason'),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $suppression
     */
    private function suppressionField(array $suppression, string $field): string
    {
        $value = $suppression[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Consumer audit suppressions require a non-empty {$field}.");
        }

        return trim($value);
    }

    private function looksLikeRegularExpression(string $value): bool
    {
        return preg_match('/^([\/#~%]).+\\1[a-z]*$/i', $value) === 1;
    }

    /**
     * @param  list<array{code: string, path: string, symbol: string, reason: string}>  $suppressions
     */
    private static function suppressed(ConsumerAuditFinding $finding, array $suppressions): bool
    {
        foreach ($suppressions as $suppression) {
            if ($finding->code === $suppression['code']
                && $finding->path === str_replace('\\', '/', $suppression['path'])
                && $finding->symbol === $suppression['symbol']) {
                return true;
            }
        }

        return false;
    }
}
