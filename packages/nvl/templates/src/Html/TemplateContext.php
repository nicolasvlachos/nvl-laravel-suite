<?php

declare(strict_types=1);

namespace Nvl\Templates\Html;

/**
 * Typed preparation context for class-based templates.
 */
final class TemplateContext
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     * @param  list<array{src: string, x_mm?: float, y_mm?: float, w_mm?: float, h_mm?: float, rotate?: float}>  $stickers
     */
    public function __construct(
        public string $language = '',
        public array $data = [],
        public array $options = [],
        public ?string $fallbackLanguage = null,
        public ?string $variant = null,
        public array $stickers = [],
        public ?string $frameKey = null,
    ) {
        $locale = config('app.locale', 'en');
        $fallback = config('app.fallback_locale', 'en');
        $this->language = $this->language !== ''
            ? $this->language
            : (is_string($locale) ? $locale : 'en');
        $this->fallbackLanguage ??= is_string($fallback) ? $fallback : 'en';
    }
}
