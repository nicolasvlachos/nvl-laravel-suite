<?php

declare(strict_types=1);

namespace Nvl\Primitives\Exceptions;

use RuntimeException;

/**
 * Reports a configured exchange rate that exceeded its explicit freshness limit.
 */
final class ExchangeRateStale extends RuntimeException
{
    public static function forPair(string $pair): self
    {
        return new self("The exchange rate for [{$pair}] is stale.");
    }
}
