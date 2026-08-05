<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Translations\Data\TranslationsDoctorCheckData;
use Nvl\Translations\Services\TranslationsDoctor;

/**
 * Reports Translations installation and adoption readiness without mutation.
 */
final class TranslationsDoctorCommand extends Command
{
    protected $signature = 'nvl:translations:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect Translations schema, profiles, routes, and bindings';

    public function handle(TranslationsDoctor $doctor): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->error('Invalid --format option. Allowed values: text, json.');

            return self::FAILURE;
        }

        $checks = $doctor->inspect();

        if ($format === 'json') {
            $this->line((string) json_encode([
                'package' => 'nvl/translations',
                'healthy' => ! $this->hasFailures($checks),
                'checks' => array_map(
                    static fn (TranslationsDoctorCheckData $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'Severity', 'Result', 'Message'],
                array_map(
                    static fn (TranslationsDoctorCheckData $check): array => [
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
     * @param  list<TranslationsDoctorCheckData>  $checks
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
