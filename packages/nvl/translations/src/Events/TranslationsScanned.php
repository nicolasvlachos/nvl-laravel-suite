<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Reports a completed source-code translation usage scan.
 */
final class TranslationsScanned implements ShouldDispatchAfterCommit
{
    /**
     * @param  array{files:int,hits:int,scanned_at:CarbonImmutable}  $result
     */
    public function __construct(public readonly array $result) {}
}
