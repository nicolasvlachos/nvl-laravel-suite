<?php

declare(strict_types=1);

namespace Nvl\Templates\Rendering;

use Illuminate\Contracts\View\Factory;
use Mpdf\Mpdf;
use Nvl\Templates\Contracts\TemplateRenderer;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Services\PdfHtmlGuard;
use Nvl\Templates\Services\PdfOptionsResolver;
use Nvl\Templates\Services\PdfTemporaryDirectoryResolver;
use UnexpectedValueException;

/**
 * Renders a trusted Blade view into bounded binary PDF output through mPDF.
 */
final readonly class MpdfTemplateRenderer implements TemplateRenderer
{
    public function __construct(
        private Factory $views,
        private PdfHtmlGuard $htmlGuard,
        private PdfOptionsResolver $optionsResolver,
        private PdfTemporaryDirectoryResolver $temporaryDirectories,
    ) {}

    /**
     * Render one template context into a PDF document.
     */
    public function render(TemplateRenderContext $context): RenderedTemplateData
    {
        $html = $this->views
            ->make($context->view, $context->viewData())
            ->render();
        $this->htmlGuard->validate($html);
        $options = $this->optionsResolver->resolve($context);
        $mpdf = new Mpdf([
            'tempDir' => $this->temporaryDirectories->resolve(
                $options->temporaryDirectory,
            ),
            'format' => $options->pageSize->value,
            'orientation' => $options->orientation->mpdfValue(),
            'margin_left' => $options->margins['left'],
            'margin_right' => $options->margins['right'],
            'margin_top' => $options->margins['top'],
            'margin_bottom' => $options->margins['bottom'],
            'margin_header' => $options->margins['header'],
            'margin_footer' => $options->margins['footer'],
            'default_font' => $options->defaultFont,
            'default_font_size' => $options->defaultFontSize,
            'dpi' => $options->dpi,
            'img_dpi' => $options->imageDpi,
            'jpgQuality' => $options->imageQuality,
            'showImageErrors' => $options->showImageErrors,
            'PDFA' => $options->pdfa,
            'PDFAauto' => $options->pdfaAuto,
            'allowAnnotationFiles' => false,
            'curlAllowUnsafeSslRequests' => false,
        ]);
        $mpdf->SetCompression($options->compress);
        $mpdf->SetTitle($options->title ?? $context->subject ?? $context->template->key);
        $mpdf->SetAuthor($options->author);
        $mpdf->SetCreator($options->creator);
        $mpdf->SetSubject($options->subject ?? $context->subject ?? '');

        if ($options->keywords !== '') {
            $mpdf->SetKeywords($options->keywords);
        }

        if ($options->headerView !== null) {
            $mpdf->SetHTMLHeader($this->renderPartial(
                $options->headerView,
                $options->headerData,
                $context,
            ));
        } elseif ($options->headerHtml !== null) {
            $this->htmlGuard->validate($options->headerHtml);
            $mpdf->SetHTMLHeader($options->headerHtml);
        }

        if ($options->footerView !== null) {
            $mpdf->SetHTMLFooter($this->renderPartial(
                $options->footerView,
                $options->footerData,
                $context,
            ));
        } elseif ($options->footerHtml !== null) {
            $this->htmlGuard->validate($options->footerHtml);
            $mpdf->SetHTMLFooter($options->footerHtml);
        }

        if ($options->protectionPermissions !== []
            || $options->userPassword !== null
            || $options->ownerPassword !== null) {
            $mpdf->SetProtection(
                $options->protectionPermissions,
                $options->userPassword ?? '',
                $options->ownerPassword,
            );
        }

        if ($options->watermark !== null && $options->watermark !== '') {
            $mpdf->SetWatermarkText($options->watermark, $options->watermarkOpacity);
            $mpdf->showWatermarkText = true;
        }

        $mpdf->WriteHTML($html);
        $content = $mpdf->OutputBinaryData();

        if (! is_string($content)) {
            throw new UnexpectedValueException('mPDF did not return binary string output.');
        }

        return new RenderedTemplateData(
            content: $content,
            mimeType: 'application/pdf',
            renderer: $context->renderer,
            byteSize: strlen($content),
            checksum: hash('sha256', $content),
            subject: $context->subject,
            suggestedFilename: $context->filename
                ?? "{$context->template->key}-{$context->locale}.pdf",
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderPartial(
        string $view,
        array $data,
        TemplateRenderContext $context,
    ): string {
        $html = $this->views
            ->make($view, [...$data, ...$context->viewData()])
            ->render();
        $this->htmlGuard->validate($html);

        return $html;
    }
}
