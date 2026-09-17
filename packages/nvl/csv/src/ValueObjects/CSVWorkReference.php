<?php

declare(strict_types=1);

namespace Nvl\Csv\ValueObjects;

/** Scalar-only identity for one tenant CSV work manifest. */
final readonly class CSVWorkReference
{
    public function __construct(
        public string $workId,
        public string $tenantId,
        public string $disk,
        public string $manifestPath,
        public string $handlerAlias,
        public int $handlerVersion,
    ) {}
}
