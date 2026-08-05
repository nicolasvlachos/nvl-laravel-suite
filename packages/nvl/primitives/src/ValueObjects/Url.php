<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Immutable absolute HTTP(S) URL with normalized scheme, host, and default port.
 */
final readonly class Url implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $value,
    ) {}

    /**
     * Validate and normalize an absolute web URL.
     */
    public static function from(string $value): static
    {
        $value = trim($value);
        $parts = parse_url($value);

        if (
            $parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true)
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw InvalidPrimitive::for('URL', 'an absolute HTTP or HTTPS URL is required.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw InvalidPrimitive::for('URL', 'credentials are not permitted in web URLs.');
        }

        $scheme = mb_strtolower($parts['scheme']);
        $host = mb_strtolower($parts['host']);
        $authority = $host;

        $port = $parts['port'] ?? null;
        if (is_int($port) && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $authority .= ':'.$port;
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return new self("{$scheme}://{$authority}{$path}{$query}{$fragment}");
    }

    /**
     * Return null instead of throwing for invalid input.
     */
    public static function tryFrom(string $value): ?self
    {
        try {
            return self::from($value);
        } catch (InvalidPrimitive) {
            return null;
        }
    }

    /**
     * Return the normalized URL scheme.
     */
    public function scheme(): string
    {
        return (string) parse_url($this->value, PHP_URL_SCHEME);
    }

    /**
     * Return the normalized URL host.
     */
    public function host(): string
    {
        return (string) parse_url($this->value, PHP_URL_HOST);
    }

    /**
     * Determine whether the URL uses HTTPS.
     */
    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /**
     * Return the canonical storage representation.
     */
    public function storageValue(): string
    {
        return $this->value;
    }

    /**
     * Determine whether another primitive has the same canonical URL.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    /**
     * Return the canonical JSON representation.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Return the canonical URL.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
