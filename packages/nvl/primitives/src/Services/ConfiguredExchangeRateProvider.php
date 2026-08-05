<?php

declare(strict_types=1);

namespace Nvl\Primitives\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Nvl\Primitives\Contracts\ExchangeRateProvider;
use Nvl\Primitives\Exceptions\ExchangeRateStale;
use Nvl\Primitives\Exceptions\ExchangeRateUnavailable;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\ValueObjects\CurrencyCode;
use Nvl\Primitives\ValueObjects\DateTimeValue;

/**
 * Resolves deterministic, explicitly directed exchange rates from configuration.
 */
final readonly class ConfiguredExchangeRateProvider implements ExchangeRateProvider
{
    /**
     * Create the configured exchange-rate provider.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Return the configured target-major-units rate for one source major unit.
     */
    public function rate(
        CurrencyCode $from,
        CurrencyCode $to,
        ?DateTimeInterface $at = null,
    ): string {
        if ($from->equals($to)) {
            return '1';
        }

        $rates = $this->config->get('primitives.exchange_rates.rates', []);

        if (! is_array($rates)) {
            throw InvalidPrimitive::for('exchange rates', 'the configured rates must be an array.');
        }

        $pair = (string) $from.'/'.(string) $to;

        if (! array_key_exists($pair, $rates)) {
            throw ExchangeRateUnavailable::between((string) $from, (string) $to);
        }

        return $this->configuredRate($rates[$pair], $pair, $at);
    }

    /**
     * Resolve and validate a scalar or freshness-aware configured rate.
     */
    private function configuredRate(
        mixed $configured,
        string $pair,
        ?DateTimeInterface $at,
    ): string {
        if (is_string($configured)) {
            return $this->assertPositiveRate($configured, $pair);
        }

        if (! is_array($configured) || ! is_string($configured['rate'] ?? null)) {
            throw InvalidPrimitive::for(
                "exchange rate [{$pair}]",
                'the rate must be an exact decimal string.',
            );
        }

        $asOf = $configured['as_of'] ?? null;
        $maximumAge = $configured['max_age_seconds'] ?? null;

        if (($asOf === null) !== ($maximumAge === null)) {
            throw InvalidPrimitive::for(
                "exchange rate [{$pair}]",
                'as_of and max_age_seconds must be configured together.',
            );
        }

        if ($asOf !== null || $maximumAge !== null) {
            if (! is_string($asOf) || ! is_int($maximumAge) || $maximumAge < 0) {
                throw InvalidPrimitive::for(
                    "exchange rate [{$pair}]",
                    'as_of must be an RFC 3339 string and max_age_seconds a non-negative integer.',
                );
            }

            $rateTime = DateTimeValue::from($asOf)->carbon();
            $reference = CarbonImmutable::instance(
                $at ?? new DateTimeImmutable('now'),
            )->utc();

            if ($rateTime->greaterThan($reference)) {
                [$from, $to] = explode('/', $pair, 2);

                throw ExchangeRateUnavailable::between($from, $to);
            }

            if ($rateTime->diffInSeconds($reference, false) > $maximumAge) {
                throw ExchangeRateStale::forPair($pair);
            }
        }

        return $this->assertPositiveRate($configured['rate'], $pair);
    }

    /**
     * Return a canonical positive decimal rate.
     */
    private function assertPositiveRate(string $rate, string $pair): string
    {
        try {
            $decimal = BigDecimal::of($rate);
        } catch (MathException $exception) {
            throw InvalidPrimitive::for(
                "exchange rate [{$pair}]",
                $exception->getMessage(),
                $exception,
            );
        }

        if ($decimal->isNegativeOrZero()) {
            throw InvalidPrimitive::for("exchange rate [{$pair}]", 'the value must be positive.');
        }

        return (string) $decimal;
    }
}
