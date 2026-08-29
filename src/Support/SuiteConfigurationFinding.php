<?php

declare(strict_types=1);

namespace Nvl\Suite\Support;

/**
 * A value-free structural package-configuration diagnostic.
 *
 * Finding paths contain configuration keys only. Consumer values are never
 * retained by this object or exposed through its serialized representation.
 */
final readonly class SuiteConfigurationFinding
{
    /**
     * @param  'error'|'warning'  $severity
     */
    public function __construct(
        public string $code,
        public string $severity,
        public string $module,
        public string $path,
        public string $message,
        public string $remediation,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     severity: 'error'|'warning',
     *     module: string,
     *     path: string,
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
            'module' => $this->module,
            'path' => $this->path,
            'symbol' => $this->path,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
