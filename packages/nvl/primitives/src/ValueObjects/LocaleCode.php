<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Scripts;

/**
 * Normalized BCP 47 language tag with validated language, script, and region parts.
 */
final readonly class LocaleCode implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $value,
    ) {}

    /**
     * Parse and canonicalize a supported BCP 47 core language tag.
     */
    public static function from(string $value): static
    {
        $value = trim($value);
        $parts = preg_split('/[-_]/', $value);

        if (
            $parts === false
            || $parts === []
            || in_array('', $parts, true)
            || ! Languages::exists(mb_strtolower($parts[0]))
        ) {
            throw InvalidPrimitive::for('locale code', "[{$value}] does not begin with a valid language code.");
        }

        $normalized = [mb_strtolower(array_shift($parts))];

        if (isset($parts[0]) && preg_match('/^[A-Za-z]{4}$/', $parts[0]) === 1) {
            $script = mb_strtoupper(mb_substr($parts[0], 0, 1))
                .mb_strtolower(mb_substr($parts[0], 1));

            if (! Scripts::exists($script)) {
                throw InvalidPrimitive::for('locale code', "[{$parts[0]}] is not a valid script subtag.");
            }

            $normalized[] = $script;
            array_shift($parts);
        }

        if (
            isset($parts[0])
            && preg_match('/^(?:[A-Za-z]{2}|\d{3})$/', $parts[0]) === 1
        ) {
            $region = mb_strtoupper($parts[0]);

            if (
                (preg_match('/^[A-Z]{2}$/', $region) === 1 && ! Countries::exists($region))
                || $region === '000'
            ) {
                throw InvalidPrimitive::for('locale code', "[{$parts[0]}] is not a valid region subtag.");
            }

            $normalized[] = $region;
            array_shift($parts);
        }

        $variants = [];

        foreach ($parts as $part) {
            $variant = mb_strtolower($part);

            if (
                preg_match('/^(?:[A-Za-z0-9]{5,8}|\d[A-Za-z0-9]{3})$/', $part) !== 1
                || in_array($variant, $variants, true)
            ) {
                throw InvalidPrimitive::for('locale code', "[{$part}] is not a valid variant subtag.");
            }

            $variants[] = $variant;
            $normalized[] = $variant;
        }

        return new self(implode('-', $normalized));
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
     * Return the normalized language subtag.
     */
    public function language(): string
    {
        return explode('-', $this->value)[0];
    }

    /**
     * Return the normalized script subtag when present.
     */
    public function script(): ?string
    {
        foreach (array_slice(explode('-', $this->value), 1) as $part) {
            if (preg_match('/^[A-Z][a-z]{3}$/', $part) === 1) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Return the normalized alpha or numeric region subtag when present.
     */
    public function regionCode(): ?string
    {
        foreach (array_slice(explode('-', $this->value), 1) as $part) {
            if (preg_match('/^(?:[A-Z]{2}|\d{3})$/', $part) === 1) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Return the region as a country when the tag uses an alpha country code.
     */
    public function region(): ?CountryCode
    {
        $region = $this->regionCode();

        return $region !== null && preg_match('/^[A-Z]{2}$/', $region) === 1
            ? CountryCode::from($region)
            : null;
    }

    /**
     * Return the canonical storage representation.
     */
    public function storageValue(): string
    {
        return $this->value;
    }

    /**
     * Determine whether another primitive has the same canonical tag.
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
     * Return the canonical BCP 47 tag.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
