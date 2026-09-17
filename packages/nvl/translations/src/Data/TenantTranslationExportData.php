<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

/** Immutable identity for one private tenant translation artifact. */
final readonly class TenantTranslationExportData
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $sha256,
        public int $bytes,
    ) {}
}
