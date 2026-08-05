<?php

declare(strict_types=1);

namespace Nvl\Content\Support;

use InvalidArgumentException;

/**
 * Validates independently configurable management and public route groups.
 */
final class ContentRouteConfiguration
{
    public static function path(string $group): string
    {
        $path = config("content.routes.{$group}.prefix", "api/v1/content/{$group}");

        if (! is_string($path)) {
            throw new InvalidArgumentException("content.routes.{$group}.prefix must be a string.");
        }

        $path = trim($path, '/');

        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '//')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $path) !== 1) {
            throw new InvalidArgumentException(
                "content.routes.{$group}.prefix must be a safe route prefix.",
            );
        }

        return $path;
    }

    public static function name(string $group): string
    {
        $name = config("content.routes.{$group}.name", "nvl.content.{$group}.");

        if (! is_string($name)) {
            throw new InvalidArgumentException("content.routes.{$group}.name must be a string.");
        }

        $name = rtrim(trim($name), '.');

        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "content.routes.{$group}.name must be a safe route-name prefix.",
            );
        }

        return $name.'.';
    }

    /**
     * @return list<string>
     */
    public static function middleware(string $group): array
    {
        $configured = config("content.routes.{$group}.middleware", ['api', 'auth']);

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                "content.routes.{$group}.middleware must be an array.",
            );
        }

        $middleware = [];

        foreach ($configured as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "Every content.routes.{$group}.middleware entry must be a non-empty string.",
                );
            }

            $middleware[] = $item;
        }

        return $middleware;
    }

    private function __construct() {}
}
