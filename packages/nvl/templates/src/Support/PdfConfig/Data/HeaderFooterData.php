<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Data;

/**
 * Trusted code-defined header and footer HTML.
 */
final readonly class HeaderFooterData
{
    public function __construct(
        public ?string $headerHtml = null,
        public ?string $footerHtml = null,
        public bool $showOnFirstPage = true,
        public bool $showOnOtherPages = true,
    ) {}
}
