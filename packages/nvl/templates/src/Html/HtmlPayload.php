<?php

declare(strict_types=1);

namespace Nvl\Templates\Html;

/**
 * Immutable HTML/CSS payload passed to a PDF engine.
 */
final readonly class HtmlPayload
{
    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public string $html,
        public string $css = '',
        public array $diagnostics = [],
    ) {}
}
