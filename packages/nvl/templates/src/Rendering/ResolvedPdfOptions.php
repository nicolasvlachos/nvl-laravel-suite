<?php

declare(strict_types=1);

namespace Nvl\Templates\Rendering;

use Nvl\Templates\Enums\PdfOrientation;
use Nvl\Templates\Enums\PdfPageSize;

/**
 * Contains the validated and fully resolved mPDF configuration for one render.
 */
final readonly class ResolvedPdfOptions
{
    /**
     * @param  array{left: float, right: float, top: float, bottom: float, header: float, footer: float}  $margins
     * @param  array<string, mixed>  $headerData
     * @param  array<string, mixed>  $footerData
     * @param  list<string>  $protectionPermissions
     */
    public function __construct(
        public PdfPageSize $pageSize,
        public PdfOrientation $orientation,
        public array $margins,
        public string $defaultFont,
        public float $defaultFontSize,
        public int $dpi,
        public int $imageDpi,
        public int $imageQuality,
        public bool $showImageErrors,
        public ?string $temporaryDirectory,
        public ?string $title,
        public string $author,
        public string $creator,
        public ?string $subject,
        public string $keywords,
        public ?string $headerView,
        public ?string $headerHtml,
        public array $headerData,
        public ?string $footerView,
        public ?string $footerHtml,
        public array $footerData,
        public ?string $watermark,
        public float $watermarkOpacity,
        public bool $compress,
        public bool $pdfa,
        public bool $pdfaAuto,
        public array $protectionPermissions,
        public ?string $userPassword,
        public ?string $ownerPassword,
    ) {}
}
