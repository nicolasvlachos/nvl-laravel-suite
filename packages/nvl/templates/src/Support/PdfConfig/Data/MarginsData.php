<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Data;

/**
 * Page margins in millimetres.
 */
final readonly class MarginsData
{
    public function __construct(
        public int $top = 0,
        public int $right = 0,
        public int $bottom = 0,
        public int $left = 0,
        public int $header = 0,
        public int $footer = 0,
    ) {}
}
