<?php

declare(strict_types=1);

namespace Nvl\Primitives\ValueObjects;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber as LibPhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Nvl\Primitives\Concerns\CastsAsScalar;
use Nvl\Primitives\Contracts\Primitive;
use Nvl\Primitives\Contracts\ScalarPrimitive;
use Nvl\Primitives\Exceptions\InvalidPrimitive;

/**
 * Valid international telephone number persisted in E.164 form.
 */
final readonly class PhoneNumber implements ScalarPrimitive
{
    use CastsAsScalar;

    private function __construct(
        private string $e164,
    ) {}

    /**
     * Parse international input or national input using the configured region.
     */
    public static function from(string $value): static
    {
        $region = config('primitives.phone.default_region');

        return self::fromRegion($value, is_string($region) ? $region : null);
    }

    /**
     * Parse input with an explicit ISO country region for national notation.
     */
    public static function fromRegion(string $value, ?string $region): self
    {
        $value = trim($value);
        $region = $region !== null ? mb_strtoupper(trim($region)) : null;
        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            $parsed = $phoneUtil->parse($value, $region);
        } catch (NumberParseException $exception) {
            throw InvalidPrimitive::for('phone number', $exception->getMessage(), $exception);
        }

        if (! $phoneUtil->isValidNumber($parsed)) {
            throw InvalidPrimitive::for('phone number', 'the number is not valid for its numbering plan.');
        }

        return new self($phoneUtil->format($parsed, PhoneNumberFormat::E164));
    }

    /**
     * Return null instead of throwing while honoring the configured default region.
     */
    public static function tryFrom(string $value, ?string $region = null): ?self
    {
        try {
            return $region === null
                ? self::from($value)
                : self::fromRegion($value, $region);
        } catch (InvalidPrimitive) {
            return null;
        }
    }

    /**
     * Return the numbering-plan country when one is available.
     */
    public function region(): ?CountryCode
    {
        $region = PhoneNumberUtil::getInstance()->getRegionCodeForNumber($this->parsed());

        return is_string($region) ? CountryCode::tryFrom($region) : null;
    }

    /**
     * Return the international display format.
     */
    public function international(): string
    {
        return PhoneNumberUtil::getInstance()->format(
            $this->parsed(),
            PhoneNumberFormat::INTERNATIONAL,
        );
    }

    /**
     * Return the national display format.
     */
    public function national(): string
    {
        return PhoneNumberUtil::getInstance()->format(
            $this->parsed(),
            PhoneNumberFormat::NATIONAL,
        );
    }

    /**
     * Return the RFC 3966 telephone URI representation.
     */
    public function rfc3966(): string
    {
        return PhoneNumberUtil::getInstance()->format(
            $this->parsed(),
            PhoneNumberFormat::RFC3966,
        );
    }

    /**
     * Return the canonical E.164 storage representation.
     */
    public function storageValue(): string
    {
        return $this->e164;
    }

    /**
     * Determine whether another primitive has the same E.164 value.
     */
    public function equals(Primitive $other): bool
    {
        return $other instanceof self && $other->e164 === $this->e164;
    }

    /**
     * Return the canonical E.164 JSON representation.
     */
    public function jsonSerialize(): string
    {
        return $this->e164;
    }

    /**
     * Return the canonical E.164 representation.
     */
    public function __toString(): string
    {
        return $this->e164;
    }

    /**
     * Parse the already validated E.164 value.
     */
    private function parsed(): LibPhoneNumber
    {
        try {
            return PhoneNumberUtil::getInstance()->parse($this->e164);
        } catch (NumberParseException $exception) {
            throw InvalidPrimitive::for('phone number', $exception->getMessage(), $exception);
        }
    }
}
