<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Data;

/**
 * PDF document metadata.
 */
final readonly class MetadataData
{
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $keywords = null,
        public ?string $creator = 'NVL Templates',
    ) {}
}
