<?php

declare(strict_types=1);

namespace Nvl\Primitives\Contracts;

use DateTimeInterface;
use Nvl\Primitives\ValueObjects\CurrencyCode;

/**
 * Resolves a positive conversion rate from one currency to another.
 */
interface ExchangeRateProvider
{
    /**
     * Return target major units for one source major unit.
     */
    public function rate(
        CurrencyCode $from,
        CurrencyCode $to,
        ?DateTimeInterface $at = null,
    ): string;
}
