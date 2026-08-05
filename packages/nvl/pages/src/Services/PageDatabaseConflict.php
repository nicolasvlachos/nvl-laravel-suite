<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Database\QueryException;
use Nvl\Pages\Exceptions\PageConflictException;

/**
 * Translates portable database uniqueness failures into a page domain conflict.
 */
final class PageDatabaseConflict
{
    /**
     * Rethrow a query failure as a page conflict only when it is a unique violation.
     *
     * @throws PageConflictException
     * @throws QueryException
     */
    public function rethrow(QueryException $exception): never
    {
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $sqlState = $errorInfo[0] ?? (string) $exception->getCode();
        $driverCode = $errorInfo[1] ?? null;
        $message = mb_strtolower($exception->getMessage());
        $isUniqueViolation = $sqlState === '23505'
            || ($sqlState === '23000'
                && in_array($driverCode, [19, 1062, '19', '1062'], true))
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'duplicate key');

        if (! $isUniqueViolation) {
            throw $exception;
        }

        throw new PageConflictException(
            'A page with the same key, sibling slug, or canonical path already exists.',
            previous: $exception,
        );
    }
}
