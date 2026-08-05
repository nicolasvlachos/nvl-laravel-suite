<?php

declare(strict_types=1);

namespace Nvl\Primitives\Services;

use Brick\Money\Exception\MoneyException;
use Illuminate\Contracts\Config\Repository;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Nvl\Primitives\ValueObjects\LocaleCode;
use Nvl\Primitives\ValueObjects\Money;

/**
 * Formats money through a locale-aware boundary with a deterministic fallback.
 */
final readonly class MoneyFormatter
{
    /**
     * Create the money formatter.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Format money for display without changing its exact value.
     */
    public function format(Money $money, ?string $locale = null): string
    {
        $locale ??= $this->configuredLocale();
        $locale = (string) LocaleCode::from($locale);

        if (! extension_loaded('intl')) {
            return $money->amount().' '.$money->currency();
        }

        try {
            return $money->toBrick()->formatToLocale($locale);
        } catch (MoneyException $exception) {
            throw InvalidPrimitive::for('money format', $exception->getMessage(), $exception);
        }
    }

    /**
     * Return the validated configured display locale.
     */
    private function configuredLocale(): string
    {
        $locale = $this->config->get('primitives.money.default_locale', 'en');

        if (! is_string($locale)) {
            throw InvalidPrimitive::for('money format', 'the default locale must be a string.');
        }

        return $locale;
    }
}
