<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

use Nvl\Forms\Exceptions\FormException;

/**
 * Parses and normalizes the host-only allowed-origin expressions used by public forms.
 */
final readonly class AllowedOriginExpression
{
    /**
     * Create a parsed allowed-origin expression.
     *
     * @param  string  $normalized  Canonical storage expression
     * @param  string  $host  Host name without wildcard prefix
     * @param  int|null  $port  Optional port restriction
     * @param  bool  $wildcardSubdomain  Whether the expression starts with `*.`
     * @param  bool  $wildcardPath  Whether the expression ends with `/*`
     */
    private function __construct(
        public string $normalized,
        public string $host,
        public ?int $port,
        public bool $wildcardSubdomain,
        public bool $wildcardPath,
    ) {}

    /**
     * Parse and normalize a raw allowed-origin expression.
     *
     * @param  string  $value  Raw allowed-origin expression
     * @return self Parsed expression
     *
     * @throws FormException When the expression is not a supported host pattern
     */
    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new FormException('Allowed origin cannot be empty.');
        }

        if (
            str_contains($trimmed, '://')
            || preg_match('/[\s\p{C}"\',;\\\\]/u', $trimmed) === 1
            || substr_count($trimmed, '/') > (str_ends_with($trimmed, '/*') ? 1 : 0)
        ) {
            throw new FormException('Allowed origin must be a host expression without scheme, whitespace, or arbitrary path.');
        }

        $wildcardPath = str_ends_with($trimmed, '/*');
        $hostExpression = $wildcardPath ? substr($trimmed, 0, -2) : $trimmed;
        $wildcardSubdomain = str_starts_with($hostExpression, '*.');
        $hostAndPort = $wildcardSubdomain ? substr($hostExpression, 2) : $hostExpression;

        if ($hostAndPort === '' || str_contains($hostAndPort, '*')) {
            throw new FormException('Allowed origin contains an invalid wildcard.');
        }

        [$host, $port] = self::splitHostAndPort($hostAndPort);
        $host = mb_strtolower($host);

        if (! self::isValidHost($host)) {
            throw new FormException('Allowed origin host is invalid.');
        }

        $normalized = ($wildcardSubdomain ? '*.' : '').$host.($port !== null ? ':'.$port : '').($wildcardPath ? '/*' : '');

        return new self(
            normalized: $normalized,
            host: $host,
            port: $port,
            wildcardSubdomain: $wildcardSubdomain,
            wildcardPath: $wildcardPath,
        );
    }

    /**
     * Normalize a raw allowed-origin expression into its canonical storage value.
     *
     * @param  string  $value  Raw allowed-origin expression
     * @return string Canonical expression
     *
     * @throws FormException When the expression is invalid
     */
    public static function normalize(string $value): string
    {
        return self::parse($value)->normalized;
    }

    /**
     * Determine whether a raw allowed-origin expression is valid.
     *
     * @param  mixed  $value  Candidate expression
     * @return bool True when the value is a supported host expression
     */
    public static function isValid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            self::parse($value);

            return true;
        } catch (FormException) {
            return false;
        }
    }

    /**
     * Build the CSP source for this expression.
     *
     * @return string CSP host-source value
     */
    public function toCspSource(): string
    {
        return 'https://'.($this->wildcardSubdomain ? '*.' : '').$this->host.($this->port !== null ? ':'.$this->port : '');
    }

    /**
     * Split a host expression into host and optional port.
     *
     * @param  string  $value  Host expression
     * @return array{0: string, 1: int|null}
     */
    private static function splitHostAndPort(string $value): array
    {
        if (str_contains($value, '[') || str_contains($value, ']')) {
            throw new FormException('IPv6 host expressions are not supported.');
        }

        $parts = explode(':', $value);
        if (count($parts) > 2) {
            throw new FormException('Allowed origin contains an invalid port separator.');
        }

        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        if ($parts[1] === '' || ! ctype_digit($parts[1])) {
            throw new FormException('Allowed origin port must be numeric.');
        }

        $port = (int) $parts[1];
        if ($port < 1 || $port > 65535) {
            throw new FormException('Allowed origin port is out of range.');
        }

        return [$parts[0], $port];
    }

    /**
     * Determine whether a host is valid for storage.
     *
     * @param  string  $host  Lowercase host name
     * @return bool True when the host is syntactically valid
     */
    private static function isValidHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if ($host === '' || strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }
}
