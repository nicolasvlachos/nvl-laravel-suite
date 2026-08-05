<?php

declare(strict_types=1);

namespace Nvl\Primitives\Exceptions;

use RuntimeException;

/**
 * Reports a currency pair for which no conversion rate is available.
 */
final class ExchangeRateUnavailable extends RuntimeException
{
    /**
     * Create an exception for one currency pair.
     */
    public static function between(string $from, string $to): self
    {
        return new self("No exchange rate is available for [{$from}/{$to}].");
    }
}
