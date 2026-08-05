<?php

declare(strict_types=1);

namespace Nvl\Templates\Pdf\Options;

use Nvl\Templates\Data\PdfOptions as PdfOptionsData;
use Nvl\Templates\Support\PdfConfig\Data\HeaderFooterData;
use Nvl\Templates\Support\PdfConfig\Data\MarginsData;
use Nvl\Templates\Support\PdfConfig\Data\MetadataData;
use Nvl\Templates\Support\PdfConfig\Data\PageNumberingData;
use Nvl\Templates\Support\PdfConfig\Data\ProtectionData;
use Nvl\Templates\Support\PdfConfig\Data\WatermarkData;
use Nvl\Templates\Support\PdfConfig\EngineConfig;
use Nvl\Templates\Support\PdfConfig\Enums\PageOrientation;
use Nvl\Templates\Support\PdfConfig\Enums\PaperSize;

/**
 * Fluent PDF options compatible with class-based template consumers.
 */
final class PdfOptions
{
    private EngineConfig $config;

    public function __construct(?EngineConfig $config = null)
    {
        $this->config = $config ?? new EngineConfig;
    }

    public static function a4Portrait(): self
    {
        return (new self)
            ->paper(PaperSize::A4)
            ->orientation(PageOrientation::PORTRAIT);
    }

    public function paper(PaperSize $size): self
    {
        $this->config->setPageSize($size);

        return $this;
    }

    public function orientation(PageOrientation $orientation): self
    {
        $this->config->setOrientation($orientation);

        return $this;
    }

    public function margins(MarginsData $margins): self
    {
        $this->config->setMargins($margins);

        return $this;
    }

    public function metadata(MetadataData $metadata): self
    {
        $this->config->setMetadata($metadata);

        return $this;
    }

    public function protection(ProtectionData $protection): self
    {
        $this->config->setProtection($protection);

        return $this;
    }

    public function watermark(WatermarkData $watermark): self
    {
        $this->config->setWatermark($watermark);

        return $this;
    }

    public function pageNumbering(PageNumberingData $pageNumbering): self
    {
        $this->config->setPageNumbering($pageNumbering);

        return $this;
    }

    public function headerFooter(HeaderFooterData $headerFooter): self
    {
        $this->config->setHeaderFooter($headerFooter);

        return $this;
    }

    public function dpi(int $dpi): self
    {
        $this->config->setDpi($dpi);

        return $this;
    }

    public function imageQuality(int $quality): self
    {
        $this->config->setImageQuality($quality);

        return $this;
    }

    public function debugImages(bool $enabled = true): self
    {
        $this->config->showImageErrors = $enabled;

        return $this;
    }

    public function toData(): PdfOptionsData
    {
        return $this->config->toPdfOptions();
    }

    /**
     * @return array<string, mixed>
     */
    public function toMpdfConfig(): array
    {
        return $this->config->toArray();
    }
}
