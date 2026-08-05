<?php

declare(strict_types=1);

namespace Nvl\Templates\Tests\Fixtures;

use Nvl\Templates\Support\PdfConfig\Data\MetadataData;
use Nvl\Templates\Support\PdfConfig\Enums\PaperSize;
use Nvl\Templates\Templates\BasePdfTemplate;

/**
 * Exercises the reusable class-template surface in an isolated consumer.
 */
final class TestClassPdfTemplate extends BasePdfTemplate
{
    protected function configure(): void
    {
        $this->setPageSize(PaperSize::A4);
        $this->setMetadata(new MetadataData(title: 'Class template'));
        $this->setOptions([
            'filename' => 'class-template.pdf',
            'storage_path' => 'template-tests',
        ]);
    }

    protected function getViewPath(): string
    {
        return 'template-tests::class-pdf';
    }

    public function getName(): string
    {
        return 'Class template';
    }

    public function getModule(): string
    {
        return 'Documents';
    }

    /**
     * @return list<string>
     */
    protected function getRequiredContent(): array
    {
        return ['heading'];
    }

    /**
     * @return list<string>
     */
    protected function getRequiredData(): array
    {
        return ['recipient_name'];
    }

    /**
     * @return list<string>
     */
    protected function getRequiredAssets(): array
    {
        return ['logo'];
    }
}
