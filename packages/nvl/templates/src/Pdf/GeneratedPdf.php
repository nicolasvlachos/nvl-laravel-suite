<?php

declare(strict_types=1);

namespace Nvl\Templates\Pdf;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Pdf\Contracts\GeneratedPdfInterface;
use Nvl\Templates\Services\SafeFilesystemPathResolver;
use Nvl\Templates\Services\TemplateResponseFactory;
use RuntimeException;
use Throwable;

/**
 * Immutable verified PDF bytes with safe response and atomic local persistence helpers.
 */
final readonly class GeneratedPdf implements GeneratedPdfInterface
{
    /** @var array<string, string> */
    private const CACHE_HEADERS = [
        'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ];

    public function __construct(
        private RenderedTemplateData $rendered,
        private TemplateResponseFactory $responses,
        private Filesystem $files,
        private SafeFilesystemPathResolver $paths,
    ) {}

    public function display(): Response
    {
        return $this->responses->inline($this->rendered, self::CACHE_HEADERS);
    }

    public function download(string $filename = 'document.pdf'): Response
    {
        return $this->responses->download(
            $this->renamed($filename),
            self::CACHE_HEADERS,
        );
    }

    public function save(string $path): string
    {
        $roots = config(
            'templates.rendering.output.allowed_local_roots',
            [storage_path()],
        );

        if (! is_array($roots)) {
            throw new InvalidArgumentException(
                'Template output allowed roots must be an array.',
            );
        }

        $path = $this->paths->file(
            $path,
            array_values($roots),
            requiredExtension: 'pdf',
            createParent: true,
            description: 'Generated PDF path',
        );
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $written = file_put_contents($temporary, $this->rendered->content, LOCK_EX);

            if ($written !== $this->rendered->byteSize
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Generated PDF could not be saved atomically.');
            }
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                $this->files->delete($temporary);
            }

            throw new RuntimeException(
                'Generated PDF could not be saved atomically.',
                0,
                $exception,
            );
        }

        return $path;
    }

    public function getContent(): string
    {
        return $this->rendered->content;
    }

    public function withFilename(string $filename): self
    {
        return new self(
            $this->renamed($filename),
            $this->responses,
            $this->files,
            $this->paths,
        );
    }

    private function renamed(string $filename): RenderedTemplateData
    {
        $filename = basename($filename);
        $filename = str_ends_with(strtolower($filename), '.pdf')
            ? $filename
            : $filename.'.pdf';

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D', $filename) !== 1
            || str_contains($filename, '..')) {
            throw new InvalidArgumentException('Generated PDF filename is invalid.');
        }

        return new RenderedTemplateData(
            content: $this->rendered->content,
            mimeType: $this->rendered->mimeType,
            renderer: $this->rendered->renderer,
            byteSize: $this->rendered->byteSize,
            checksum: $this->rendered->checksum,
            subject: $this->rendered->subject,
            suggestedFilename: $filename,
        );
    }
}
