<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Data;

/**
 * Text watermark configuration.
 */
final readonly class WatermarkData
{
    public function __construct(
        public ?string $text = null,
        public float $alpha = 0.1,
        public bool $show = false,
    ) {}
}
