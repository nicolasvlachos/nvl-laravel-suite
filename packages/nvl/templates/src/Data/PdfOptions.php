<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Templates\Enums\PdfOrientation;
use Nvl\Templates\Enums\PdfPageSize;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed per-template overrides for the bundled mPDF implementation.
 */
#[TypeScript]
final class PdfOptions extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $headerData
     * @param  array<string, mixed>  $footerData
     * @param  list<string>  $protectionPermissions
     */
    public function __construct(
        public readonly ?PdfPageSize $pageSize = null,
        public readonly ?PdfOrientation $orientation = null,
        public readonly ?PdfMargins $margins = null,
        public readonly ?string $defaultFont = null,
        public readonly ?float $defaultFontSize = null,
        public readonly ?int $dpi = null,
        public readonly ?int $imageDpi = null,
        public readonly ?int $imageQuality = null,
        public readonly ?bool $showImageErrors = null,
        public readonly ?string $temporaryDirectory = null,
        public readonly ?string $title = null,
        public readonly ?string $author = null,
        public readonly ?string $creator = null,
        public readonly ?string $subject = null,
        public readonly ?string $keywords = null,
        public readonly ?string $headerView = null,
        public readonly ?string $headerHtml = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $headerData = [],
        public readonly ?string $footerView = null,
        public readonly ?string $footerHtml = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $footerData = [],
        public readonly ?string $watermark = null,
        public readonly ?float $watermarkOpacity = null,
        public readonly ?bool $compress = null,
        public readonly ?bool $pdfa = null,
        public readonly ?bool $pdfaAuto = null,
        public readonly array $protectionPermissions = [],
        public readonly ?string $userPassword = null,
        public readonly ?string $ownerPassword = null,
    ) {}
}
