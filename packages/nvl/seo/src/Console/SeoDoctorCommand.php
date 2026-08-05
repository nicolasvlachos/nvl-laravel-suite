<?php

declare(strict_types=1);

namespace Nvl\Seo\Console;

use Illuminate\Console\Command;
use Nvl\Seo\Data\SeoDoctorCheckData;
use Nvl\Seo\Services\SeoDoctor;

/**
 * Reports SEO installation and adoption readiness without mutation.
 */
final class SeoDoctorCommand extends Command
{
    protected $signature = 'nvl:seo:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect SEO schema, routes, and extension bindings';

    public function handle(SeoDoctor $doctor): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->components->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $checks = $doctor->inspect();

        if ($format === 'json') {
            $this->line((string) json_encode([
                'package' => 'nvl/seo',
                'healthy' => ! $this->hasFailures($checks),
                'checks' => array_map(
                    static fn (SeoDoctorCheckData $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'Severity', 'Result', 'Message'],
                array_map(
                    static fn (SeoDoctorCheckData $check): array => [
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
     * @param  list<SeoDoctorCheckData>  $checks
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
