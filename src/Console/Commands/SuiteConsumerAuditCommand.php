<?php

declare(strict_types=1);

namespace Nvl\Suite\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Nvl\Suite\Services\SuiteConsumerAuditor;
use Nvl\Suite\Support\ConsumerAuditFinding;
use Throwable;

/**
 * Audits an application's package boundaries and Suite adoption wiring.
 */
final class SuiteConsumerAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:suite:consumer-audit
        {path? : Consumer application root; defaults to base_path()}
        {--strict : Fail for errors and strict adoption warnings}
        {--format=table : Output format: table or json}';

    /** @var string */
    protected $description = 'Audit application use of NVL package boundaries and runtime adoption requirements';

    /**
     * Run the read-only consumer audit.
     */
    public function handle(
        SuiteConsumerAuditor $auditor,
        Repository $configuration,
    ): int {
        $path = $this->argument('path');
        $format = $this->option('format');

        if ($path !== null && trim($path) === '') {
            $this->components->error('The consumer path must be a non-empty directory path.');

            return self::INVALID;
        }

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('The --format option must be table or json.');

            return self::INVALID;
        }

        $consumerPath = $path ?? base_path();

        try {
            $findings = $auditor->audit($consumerPath);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::INVALID;
        }

        $runtimeChecked = $auditor->runtimeChecked($consumerPath);

        if ($format === 'json') {
            $this->line((string) json_encode([
                'healthy' => ! $this->fails($findings, (bool) $this->option('strict'), $configuration),
                'strict' => (bool) $this->option('strict'),
                'runtime_checked' => $runtimeChecked,
                'findings' => array_map(
                    static fn (ConsumerAuditFinding $finding): array => $finding->toArray(),
                    $findings,
                ),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            if (! $runtimeChecked) {
                $this->components->warn(
                    'Runtime checks were skipped because the target is not the booted application.',
                );
            }

            $this->table(
                ['Code', 'Severity', 'Package', 'Location', 'Symbol', 'Remediation'],
                array_map(static fn (ConsumerAuditFinding $finding): array => [
                    $finding->code,
                    $finding->severity,
                    $finding->package ?? 'suite',
                    $finding->path.($finding->line === null ? '' : ':'.$finding->line),
                    $finding->symbol,
                    $finding->remediation,
                ], $findings),
            );
        }

        return $this->fails($findings, (bool) $this->option('strict'), $configuration)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  list<ConsumerAuditFinding>  $findings
     */
    private function fails(array $findings, bool $strict, Repository $configuration): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity === 'error') {
                return true;
            }

            if ($strict
                && $finding->code === 'consumer.implicit_module_decision'
                && $configuration->get(
                    'nvl-suite.adoption.require_explicit_module_decisions',
                    false,
                ) === true) {
                return true;
            }
        }

        return false;
    }
}
