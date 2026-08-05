<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use Illuminate\Database\QueryException;

/**
 * Identifies portable integrity-constraint failures from database exceptions.
 */
final class DatabaseConstraintViolation
{
    /**
     * Determine whether an exception represents an integrity constraint violation.
     */
    public static function isIntegrityViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return is_string($sqlState) && str_starts_with($sqlState, '23');
    }

    /**
     * Determine whether an integrity failure references any known identity column or constraint.
     *
     * @param  list<string>  $identifiers
     */
    public static function matches(QueryException $exception, array $identifiers): bool
    {
        if (! self::isIntegrityViolation($exception)) {
            return false;
        }

        $errorInfo = array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $exception->errorInfo ?? [],
        );
        $haystack = mb_strtolower(implode(' ', [
            $exception->getMessage(),
            ...$errorInfo,
        ]));

        foreach ($identifiers as $identifier) {
            if (str_contains($haystack, mb_strtolower($identifier))) {
                return true;
            }
        }

        return false;
    }

    private function __construct() {}
}
