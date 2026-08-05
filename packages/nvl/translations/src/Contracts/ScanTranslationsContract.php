<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Carbon\CarbonImmutable;

/**
 * Defines the source-code usage scanning entrypoint.
 */
interface ScanTranslationsContract
{
    /**
     * Scan configured source roots and persist one completed run.
     *
     * @return array{files:int,hits:int,scanned_at:CarbonImmutable}
     */
    public function execute(): array;
}
