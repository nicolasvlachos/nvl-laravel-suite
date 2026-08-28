<?php

declare(strict_types=1);

namespace Nvl\Suite\Support;

/**
 * Describes one actionable consumer-boundary or adoption finding.
 */
final readonly class ConsumerAuditFinding
{
    /** @var list<string> */
    public const array CODES = [
        'consumer.package_model_query',
        'consumer.package_model_write',
        'consumer.package_migration_reference',
        'consumer.package_table_reference',
        'consumer.duplicate_package_migration',
        'consumer.implicit_module_decision',
        'consumer.missing_auth_binding',
        'consumer.unsafe_management_route',
        'consumer.missing_required_schedule',
        'consumer.stale_generated_contract',
        'consumer.stale_suite_skill',
    ];

    public function __construct(
        public string $code,
        public string $severity,
        public ?string $package,
        public string $path,
        public ?int $line,
        public string $symbol,
        public string $message,
        public string $remediation,
    ) {}

    /**
     * Return the secret-free machine representation.
     *
     * @return array{
     *     code: string,
     *     severity: string,
     *     package: string|null,
     *     path: string,
     *     line: int|null,
     *     symbol: string,
     *     message: string,
     *     remediation: string
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'package' => $this->package,
            'path' => $this->path,
            'line' => $this->line,
            'symbol' => $this->symbol,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
