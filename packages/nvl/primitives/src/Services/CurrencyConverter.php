<?php

declare(strict_types=1);

namespace Nvl\Primitives\Services;

use Brick\Math\RoundingMode;
use DateTimeInterface;
use Nvl\Primitives\Contracts\ExchangeRateProvider;
use Nvl\Primitives\ValueObjects\CurrencyCode;
use Nvl\Primitives\ValueObjects\Money;

/**
 * Converts exact money through an injected exchange-rate boundary.
 */
final readonly class CurrencyConverter
{
    /**
     * Create the converter with its exchange-rate boundary.
     */
    public function __construct(
        private ExchangeRateProvider $rates,
    ) {}

    /**
     * Convert money using an explicit rounding mode and optional effective instant.
     */
    public function convert(
        Money $money,
        CurrencyCode|string $target,
        RoundingMode $roundingMode,
        ?DateTimeInterface $at = null,
    ): Money {
        $target = $target instanceof CurrencyCode ? $target : CurrencyCode::from($target);
        $rate = $this->rates->rate($money->currency(), $target, $at);

        return $money->convert($target, $rate, $roundingMode);
    }
}
