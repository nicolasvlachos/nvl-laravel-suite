<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Nvl\Templates\Data\RenderedTemplateData;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Creates safe inline or attachment HTTP responses from verified render output.
 */
final readonly class TemplateResponseFactory
{
    public function __construct(private ResponseFactory $responses) {}

    /**
     * Create an inline response suitable for browser display.
     *
     * @param  array<string, string>  $headers
     */
    public function inline(
        RenderedTemplateData $rendered,
        array $headers = [],
    ): Response {
        return $this->response($rendered, 'inline', $headers);
    }

    /**
     * Create an attachment response using the suggested safe filename.
     *
     * @param  array<string, string>  $headers
     */
    public function download(
        RenderedTemplateData $rendered,
        array $headers = [],
    ): Response {
        if ($rendered->suggestedFilename === null) {
            throw new InvalidArgumentException(
                'A rendered template requires a suggested filename for download.',
            );
        }

        return $this->response($rendered, 'attachment', $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function response(
        RenderedTemplateData $rendered,
        string $disposition,
        array $headers,
    ): Response {
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || preg_match('/[\\r\\n]/', $value) === 1) {
                throw new InvalidArgumentException(
                    'Template response headers must be safe strings.',
                );
            }
        }

        $protected = [
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
            'Content-Type' => $rendered->mimeType,
            'Content-Length' => (string) $rendered->byteSize,
            'ETag' => '"'.$rendered->checksum.'"',
            'Expires' => '0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($rendered->suggestedFilename !== null) {
            $protected['Content-Disposition'] = HeaderUtils::makeDisposition(
                $disposition,
                $rendered->suggestedFilename,
            );
        }

        return $this->responses->make(
            $rendered->content,
            200,
            [...$headers, ...$protected],
        );
    }
}
