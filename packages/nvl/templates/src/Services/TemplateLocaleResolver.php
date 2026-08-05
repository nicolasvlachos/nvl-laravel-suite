<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;

/**
 * Normalizes template locales into one stable lowercase BCP-47-like form.
 */
final class TemplateLocaleResolver
{
    /**
     * Resolve and validate one template locale.
     */
    public function resolve(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Template locale must be a non-empty string.');
        }

        $locale = mb_strtolower(str_replace('_', '-', trim($value)));

        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale) !== 1) {
            throw new InvalidArgumentException("Template locale [{$locale}] is invalid.");
        }

        return $locale;
    }
}
