<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Support\AllowedOriginExpression;

/**
 * Matches incoming origin/host values against allowed-origin patterns.
 */
final class OriginMatchingService
{
    /**
     * Determine whether a host matches an allowed origin pattern.
     */
    public function matches(string $originPattern, string $host): bool
    {
        try {
            $expression = AllowedOriginExpression::parse($originPattern);
        } catch (FormException) {
            return false;
        }

        $normalizedHost = mb_strtolower(trim($this->extractHost($host)));
        [$hostName, $hostPort] = $this->splitHostAndPort($normalizedHost);

        if ($hostName === '') {
            return false;
        }

        // Exact host match (including optional port match when configured).
        if (! $expression->wildcardSubdomain && $hostName === $expression->host && $this->portMatches($expression->port, $hostPort)) {
            return true;
        }

        // Wildcard subdomain support (*.example.com) with proper boundary checks.
        if ($expression->wildcardSubdomain) {
            $isSubdomain = $hostName !== $expression->host && str_ends_with($hostName, '.'.$expression->host);

            return $isSubdomain && $this->portMatches($expression->port, $hostPort);
        }

        return false;
    }

    /**
     * Split a host expression into host name and optional numeric port.
     *
     * @return array{0: string, 1: int|null}
     */
    private function splitHostAndPort(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['', null];
        }

        $parts = explode(':', $trimmed);
        if (count($parts) === 2 && ctype_digit($parts[1])) {
            return [$parts[0], (int) $parts[1]];
        }

        return [$trimmed, null];
    }

    /**
     * Check whether the configured origin port matches the request host port.
     */
    private function portMatches(?int $originPort, ?int $hostPort): bool
    {
        return $originPort === null || $originPort === $hostPort;
    }

    /**
     * Extract host (and port) from a URL-like value or return the raw value.
     */
    private function extractHost(string $value): string
    {
        $parsed = parse_url($value);

        if ($parsed === false || ! isset($parsed['host'])) {
            return $value;
        }

        $host = (string) $parsed['host'];

        if (isset($parsed['port'])) {
            $host .= ':'.(string) $parsed['port'];
        }

        return $host;
    }
}
