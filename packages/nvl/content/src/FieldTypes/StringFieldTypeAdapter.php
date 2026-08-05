<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use DateTimeImmutable;
use InvalidArgumentException;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Support\ContentUriSchemePolicy;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Normalizes bounded text, date, URL, email, color, and select values.
 */
final class StringFieldTypeAdapter extends AbstractFieldTypeAdapter
{
    public function __construct(private readonly string $type) {}

    public function alias(): string
    {
        return $this->type;
    }

    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Content field [{$context->path}] must be a string.");
        }

        $maximum = $field->setting(
            'max_length',
            ContentConfiguration::positiveInteger(
                'content.validation.maximum_string_length',
                100_000,
            ),
        );

        if (! is_int($maximum) || $maximum < 1 || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] exceeds its string limit.",
            );
        }

        $minimum = $field->setting('min_length', 0);

        if (! is_int($minimum) || $minimum < 0 || mb_strlen($value) < $minimum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] is shorter than its minimum.",
            );
        }

        if ($this->type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Content field [{$context->path}] is not an email.");
        }

        if ($this->type === 'url') {
            $this->assertUrl($value, $field, $context);
        }

        if ($this->type === 'uri') {
            $this->assertUri($value, $field, $context);
        }

        if ($this->type === 'color'
            && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) !== 1) {
            throw new InvalidArgumentException("Content field [{$context->path}] is not a hex color.");
        }

        if ($this->type === 'date' && ! $this->validDate($value, 'Y-m-d')) {
            throw new InvalidArgumentException("Content field [{$context->path}] is not an ISO date.");
        }

        if ($this->type === 'date_time' && ! $this->validDateTime($value)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] is not an ISO 8601 date-time.",
            );
        }

        if ($this->type === 'select') {
            $this->assertOption($value, $field, $context);
        }

        $pattern = $field->setting('pattern');

        if (is_string($pattern) && @preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] does not match its pattern.",
            );
        }

        return $value;
    }

    private function validDate(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat($format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    private function validDateTime(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false
                || $errors['warning_count'] === 0 && $errors['error_count'] === 0);
    }

    private function assertUrl(
        string $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Content field [{$context->path}] is not a URL.");
        }

        $allowed = $field->setting(
            'allowed_schemes',
            ContentConfiguration::stringList('content.validation.url_schemes'),
        );
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_array($allowed)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] uses an unavailable or unsafe URL.",
            );
        }

        $allowed = ContentUriSchemePolicy::runtimeAllowedSchemes($allowed);

        if ($allowed === []
            || ! is_string($scheme)
            || ! is_string($host)
            || $host === ''
            || ! ContentUriSchemePolicy::allows($scheme, $allowed)
            || parse_url($value, PHP_URL_USER) !== null
            || parse_url($value, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] uses an unavailable or unsafe URL.",
            );
        }
    }

    private function assertOption(
        string $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void {
        $options = $field->setting('options', []);

        $allowed = ! is_array($options)
            ? []
            : (array_is_list($options) ? $options : array_keys($options));

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] contains an unavailable option.",
            );
        }
    }

    private function assertUri(
        string $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): void {
        if ($value === ''
            || preg_match('/[\x00-\x20\x7F]/u', $value) === 1
            || str_contains($value, '\\')
            || str_starts_with($value, '//')) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] is not a safe URI.",
            );
        }

        $allowRelative = config('content.links.allow_relative', true);

        if ($allowRelative === true
            && (str_starts_with($value, '/')
                || str_starts_with($value, '#')
                || str_starts_with($value, '?'))) {
            return;
        }

        $allowed = $field->setting(
            'allowed_schemes',
            ContentConfiguration::stringList('content.links.allowed_schemes'),
        );
        $parsedScheme = parse_url($value, PHP_URL_SCHEME);

        if (! is_array($allowed)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] uses an unavailable URI scheme.",
            );
        }

        $allowed = ContentUriSchemePolicy::runtimeAllowedSchemes($allowed);

        if ($allowed === []
            || ! is_string($parsedScheme)
            || ! ContentUriSchemePolicy::allows($parsedScheme, $allowed)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] uses an unavailable URI scheme.",
            );
        }

        $scheme = mb_strtolower($parsedScheme);

        if ($scheme === 'https') {
            $host = parse_url($value, PHP_URL_HOST);

            if (filter_var($value, FILTER_VALIDATE_URL) === false
                || ! is_string($host)
                || $host === ''
                || parse_url($value, PHP_URL_USER) !== null
                || parse_url($value, PHP_URL_PASS) !== null) {
                throw new InvalidArgumentException(
                    "Content field [{$context->path}] is not a safe HTTPS URI.",
                );
            }

            return;
        }

        if ($scheme === 'mailto') {
            $address = explode('?', mb_substr($value, 7), 2)[0];

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(
                    "Content field [{$context->path}] is not a valid mail URI.",
                );
            }

            return;
        }

        if ($scheme === 'tel'
            && preg_match('/^tel:\\+?[0-9(). -]{3,40}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] is not a valid telephone URI.",
            );
        }
    }
}
