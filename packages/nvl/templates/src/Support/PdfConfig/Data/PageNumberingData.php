<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Data;

use InvalidArgumentException;

/**
 * Page-numbering behavior expressed with mPDF placeholders.
 */
final readonly class PageNumberingData
{
    public function __construct(
        public bool $enabled = false,
        public string $position = 'bottom-center',
        public string $template = '{PAGENO}/{nbpg}',
    ) {
        if (! in_array($this->position, [
            'top-left',
            'top-center',
            'top-right',
            'bottom-left',
            'bottom-center',
            'bottom-right',
        ], true)) {
            throw new InvalidArgumentException('PDF page-number position is invalid.');
        }
    }
}
