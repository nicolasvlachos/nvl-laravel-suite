<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Support\LocaleCode;

/**
 * Resolves Content's effective locale allowlist and request-scoped default.
 */
final readonly class ContentLocalePolicy
{
    public function __construct(
        private LocaleRegistry $locales,
        private ContentLocale $contentLocale,
    ) {}

    /**
     * @return list<string>
     */
    public function available(): array
    {
        $configured = ContentConfiguration::stringList('content.locales.available');
        $registered = $this->locales->supported();
        $available = $configured === [] ? $registered : $configured;
        $normalized = [];

        foreach ($available as $locale) {
            $value = (new LocaleCode($locale))->value;

            if (! in_array($value, $registered, true)) {
                throw new InvalidArgumentException(
                    "Content locale [{$value}] is not registered in translatable.locales.",
                );
            }

            if (in_array($value, $normalized, true)) {
                throw new InvalidArgumentException(
                    "Content locale [{$locale}] is configured more than once.",
                );
            }

            $normalized[] = $value;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Content requires at least one available locale.');
        }

        return $normalized;
    }

    public function assertSupported(string $locale): string
    {
        $normalized = (new LocaleCode($locale))->value;

        if (! in_array($normalized, $this->available(), true)) {
            throw new InvalidArgumentException("Content locale [{$normalized}] is not configured.");
        }

        return $normalized;
    }

    public function current(): string
    {
        $current = $this->contentLocale->get();

        return in_array($current, $this->available(), true)
            ? $current
            : $this->available()[0];
    }
}
