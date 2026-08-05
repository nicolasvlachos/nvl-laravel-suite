<?php

declare(strict_types=1);

namespace Nvl\Templates\Pdf;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Nvl\Templates\Actions\RenderTemplateAction;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Html\HtmlPayload;
use Nvl\Templates\Pdf\Contracts\GeneratedPdfInterface;
use Nvl\Templates\Pdf\Contracts\PdfServiceInterface;
use Nvl\Templates\Pdf\Options\PdfOptions;
use Nvl\Templates\Services\SafeFilesystemPathResolver;
use Nvl\Templates\Services\TemplateResponseFactory;
use Nvl\Templates\Template;
use Nvl\Templates\Templates\Contracts\TemplateInterface;
use RuntimeException;

/**
 * Class-template adapter over the package's single verified rendering pipeline.
 */
final readonly class PdfService implements PdfServiceInterface
{
    public function __construct(
        private RenderTemplateAction $render,
        private TemplateResponseFactory $responses,
        private Filesystem $files,
        private FilesystemFactory $storage,
        private SafeFilesystemPathResolver $paths,
    ) {}

    public function renderHtml(
        HtmlPayload $payload,
        PdfOptions $options,
    ): GeneratedPdfInterface {
        $rendered = $this->render->execute(new Template(
            key: 'runtime.pdf',
            view: 'nvl-templates::pdf.html-payload',
            data: [
                'html' => $payload->html,
                'css' => $payload->css,
                'diagnostics' => $payload->diagnostics,
            ],
            options: new TemplateOptions(
                renderer: 'pdf',
                filename: 'document.pdf',
                pdf: $options->toData(),
            ),
        ));

        return new GeneratedPdf(
            $rendered,
            $this->responses,
            $this->files,
            $this->paths,
        );
    }

    public function saveHtml(
        HtmlPayload $payload,
        PdfOptions $options,
        string $path,
    ): string {
        return $this->renderHtml($payload, $options)->save($path);
    }

    public function renderTemplate(
        TemplateInterface $template,
        string $language,
        array $data = [],
        array $assets = [],
        array $options = [],
    ): GeneratedPdfInterface {
        $template
            ->setLanguage($language)
            ->setData($data)
            ->setAssets($assets)
            ->setOptions($options);
        $payload = $template->render();
        $generated = $this->renderHtml(
            new HtmlPayload($payload['html'], $payload['css']),
            new PdfOptions($template->getConfig()),
        );
        $filename = $template->getDefaultFilename($data) ?? 'document.pdf';

        return $generated instanceof GeneratedPdf
            ? $generated->withFilename($filename)
            : $generated;
    }

    public function saveWithTemplate(
        TemplateInterface $template,
        string $language,
        array $data = [],
        array $assets = [],
        array $options = [],
        ?string $filename = null,
    ): string {
        $filename = $this->pdfFilename(
            $filename ?? $template->getDefaultFilename($data) ?? 'document.pdf',
        );
        $path = $template->getStoragePath() ?? 'templates';

        return $this->renderTemplate(
            $template,
            $language,
            $data,
            $assets,
            $options,
        )->save(storage_path('app/private/'.trim($path, '/').'/'.$filename));
    }

    public function saveToStorage(
        TemplateInterface $template,
        string $language,
        array $data = [],
        array $assets = [],
        array $options = [],
        ?string $filename = null,
    ): string {
        $filename = $this->pdfFilename(
            $filename ?? $template->getDefaultFilename($data) ?? 'document.pdf',
        );
        $directory = $template->getStoragePath() ?? 'templates';
        $path = trim($directory, '/').'/'.$filename;

        if (str_contains($path, '..')
            || str_starts_with($path, '/')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/._-]*$#D', $path) !== 1) {
            throw new InvalidArgumentException('Template storage output path is invalid.');
        }

        $configuredDisk = config('templates.rendering.output.disk');
        $disk = $template->getStorageDisk()
            ?? (is_string($configuredDisk) ? $configuredDisk : 'local');
        $written = $this->storage->disk($disk)->put(
            $path,
            $this->renderTemplate(
                $template,
                $language,
                $data,
                $assets,
                $options,
            )->getContent(),
            ['visibility' => 'private'],
        );

        if (! $written) {
            throw new RuntimeException("Generated PDF could not be stored on disk [{$disk}].");
        }

        return $path;
    }

    /**
     * Normalize a caller-supplied PDF filename to one safe basename.
     */
    private function pdfFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = str_ends_with(mb_strtolower($filename), '.pdf')
            ? $filename
            : $filename.'.pdf';

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D', $filename) !== 1
            || str_contains($filename, '..')) {
            throw new InvalidArgumentException('Template PDF filename is invalid.');
        }

        return $filename;
    }
}
