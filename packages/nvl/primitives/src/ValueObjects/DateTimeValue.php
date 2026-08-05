<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Timezone-safe instant stored as an ISO 8601 UTC timestamp.
 */
final readonly class DateTimeValue implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private CarbonImmutable $value,
    ) {}

    /**
     * Parse a timezone-qualified RFC 3339 instant.
     */
    public static function from(string $value): static
    {
        $value = trim($value);

        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d{1,6}))?(?<timezone>[Zz]|[+-]\d{2}:\d{2})$/D',
            $value,
            $matches,
        ) !== 1) {
            throw InvalidPrimitive::for(
                'date-time',
                'a timezone-qualified RFC 3339 timestamp is required.',
            );
        }

        $fraction = str_pad($matches['fraction'], 6, '0');
        $date = str_replace('t', 'T', $matches['date']);
        $timezone = mb_strtoupper($matches['timezone']) === 'Z'
            ? '+00:00'
            : $matches['timezone'];
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            "{$date}.{$fraction}{$timezone}",
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw InvalidPrimitive::for('date-time', 'the timestamp is not a valid calendar instant.');
        }

        return new self(CarbonImmutable::instance($parsed)->utc());
    }

    /**
     * Create an instant from a native date-time value.
     */
    public static function fromDateTime(DateTimeInterface $value): self
    {
        return new self(CarbonImmutable::instance($value)->utc());
    }

    /**
     * Create an instant for the current UTC time.
     */
    public static function now(): self
    {
        return new self(CarbonImmutable::now('UTC'));
    }

    /**
     * Return the immutable Carbon representation in UTC.
     */
    public function carbon(): CarbonImmutable
    {
        return $this->value;
    }

    /**
     * Return the instant represented in a validated IANA timezone.
     */
    public function inTimezone(string $timezone): CarbonImmutable
    {
        return $this->value->setTimezone((string) TimezoneId::from($timezone));
    }

    /**
     * Return the canonical UTC storage representation.
     */
    public function storageValue(): string
    {
        return $this->canonicalValue();
    }

    /**
     * Determine whether another primitive represents the same instant.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->value->equalTo($this->value);
    }

    /**
     * Return the canonical JSON representation.
     */
    public function jsonSerialize(): string
    {
        return $this->canonicalValue();
    }

    /**
     * Return the canonical UTC representation.
     */
    public function __toString(): string
    {
        return $this->canonicalValue();
    }

    /**
     * Format the instant with fixed microsecond precision and a UTC designator.
     */
    private function canonicalValue(): string
    {
        return $this->value->format('Y-m-d\TH:i:s.u\Z');
    }
}
