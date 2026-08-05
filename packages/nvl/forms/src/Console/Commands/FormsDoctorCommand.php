<?php

declare(strict_types=1);

namespace Nvl\Forms\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Forms\Data\FormsDoctorCheckData;
use Nvl\Forms\Services\FormsDoctor;

/**
 * Reports Forms installation and adoption readiness without mutation.
 */
final class FormsDoctorCommand extends Command
{
    protected $signature = 'nvl:forms:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect Forms schema, routes, authorization, and privacy bindings';

    public function handle(FormsDoctor $doctor): int
    {
        $checks = $doctor->inspect();

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode([
                'package' => 'nvl/forms',
                'healthy' => ! $this->hasFailures($checks),
                'checks' => array_map(
                    static fn (FormsDoctorCheckData $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'Severity', 'Result', 'Message'],
                array_map(
                    static fn (FormsDoctorCheckData $check): array => [
                        $check->key,
                        $check->severity,
                        $check->passed ? 'PASS' : 'FAIL',
                        $check->message,
                    ],
                    $checks,
                ),
            );
        }

        return $this->hasFailures($checks) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<FormsDoctorCheckData>  $checks
     */
    private function hasFailures(array $checks): bool
    {
        $strict = (bool) $this->option('strict');

        foreach ($checks as $check) {
            if (! $check->passed && ($check->severity === 'error' || $strict)) {
                return true;
            }
        }

        return false;
    }
}
