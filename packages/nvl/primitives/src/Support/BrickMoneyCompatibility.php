<?php

declare(strict_types=1);

namespace Nvl\Primitives\Support;

use InvalidArgumentException;
use ReflectionMethod;

/**
 * Bridges Brick Money's allocation API before and after version 0.14.
 *
 * @internal
 */
final class BrickMoneyCompatibility
{
    /**
     * @param  list<string|int>  $ratios
     */
    public static function allocate(
        object $money,
        array $ratios,
        ?bool $modernApi = null,
    ): mixed {
        $modernApi ??= class_exists('Brick\\Money\\AllocationMode');

        if (! $modernApi) {
            $legacyRatios = array_map(
                static fn (string|int $ratio): int => filter_var(
                    $ratio,
                    FILTER_VALIDATE_INT,
                    FILTER_NULL_ON_FAILURE,
                ) ?? throw new InvalidArgumentException(
                    'Brick Money 0.11 only supports integer allocation ratios.',
                ),
                $ratios,
            );

            return (new ReflectionMethod($money, 'allocate'))->invokeArgs(
                $money,
                $legacyRatios,
            );
        }

        return (new ReflectionMethod($money, 'allocate'))->invoke(
            $money,
            $ratios,
            constant('Brick\\Money\\AllocationMode::FloorToFirst'),
        );
    }

    public static function split(
        object $money,
        int $parts,
        ?bool $modernApi = null,
    ): mixed {
        $modernApi ??= class_exists('Brick\\Money\\SplitMode');

        if (! $modernApi) {
            return (new ReflectionMethod($money, 'split'))->invoke($money, $parts);
        }

        return (new ReflectionMethod($money, 'split'))->invoke(
            $money,
            $parts,
            constant('Brick\\Money\\SplitMode::ToFirst'),
        );
    }
}
