<?php

declare(strict_types=1);

namespace Nvl\Primitives\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Bridges Brick Math's renamed enum cases and decimal normalization methods.
 */
final class BrickMathCompatibility
{
    public static function unnecessary(): RoundingMode
    {
        return self::roundingMode('Unnecessary', 'UNNECESSARY');
    }

    public static function halfUp(): RoundingMode
    {
        return self::roundingMode('HalfUp', 'HALF_UP');
    }

    public static function stripTrailingZeros(BigDecimal $value): BigDecimal
    {
        $method = (new ReflectionClass($value))->hasMethod('strippedOfTrailingZeros')
            ? 'strippedOfTrailingZeros'
            : 'stripTrailingZeros';
        $normalized = (new ReflectionMethod($value, $method))->invoke($value);

        if (! $normalized instanceof BigDecimal) {
            throw new LogicException("Brick Math decimal method [{$method}] returned an invalid value.");
        }

        return $normalized;
    }

    private static function roundingMode(string $modern, string $legacy): RoundingMode
    {
        $constant = RoundingMode::class.'::'.(
            defined(RoundingMode::class.'::'.$modern) ? $modern : $legacy
        );
        $mode = constant($constant);

        if (! $mode instanceof RoundingMode) {
            throw new LogicException("Brick Math rounding mode [{$constant}] is unavailable.");
        }

        return $mode;
    }
}
