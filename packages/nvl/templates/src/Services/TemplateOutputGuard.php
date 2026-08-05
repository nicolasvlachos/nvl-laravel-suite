<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Rendering\TemplateRenderContext;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Verifies renderer output facts and global size limits before returning bytes.
 */
final class TemplateOutputGuard
{
    /**
     * Verify one renderer result against its context and declared facts.
     */
    public function validate(
        TemplateRenderContext $context,
        RenderedTemplateData $rendered,
    ): void {
        $maximum = TemplatesConfiguration::limit('output_bytes', 25_165_824);
        $actualSize = strlen($rendered->content);

        if ($actualSize > $maximum) {
            throw new InvalidArgumentException(
                "Rendered template exceeds the configured {$maximum} byte limit.",
            );
        }

        if ($rendered->byteSize !== $actualSize
            || ! hash_equals(hash('sha256', $rendered->content), $rendered->checksum)) {
            throw new InvalidArgumentException(
                'Rendered template size or checksum facts are invalid.',
            );
        }

        if ($rendered->renderer !== $context->renderer) {
            throw new InvalidArgumentException(
                'Rendered template driver does not match the requested renderer.',
            );
        }

        if (preg_match(
            '/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+(?:;\s*charset=[a-z0-9._-]+)?$/Di',
            $rendered->mimeType,
        ) !== 1) {
            throw new InvalidArgumentException('Rendered template MIME type is invalid.');
        }

        if (str_starts_with(mb_strtolower($rendered->mimeType), 'application/pdf')
            && ! str_starts_with($rendered->content, '%PDF-')) {
            throw new InvalidArgumentException('PDF renderer output has an invalid signature.');
        }

        if ($rendered->suggestedFilename !== null) {
            $this->validateFilename($rendered->suggestedFilename);
        }

        if ($rendered->subject !== null
            && (mb_strlen($rendered->subject) > 998
                || preg_match('/[\\r\\n]/', $rendered->subject) === 1)) {
            throw new InvalidArgumentException('Rendered template subject is invalid.');
        }
    }

    /**
     * Verify that an output filename is a bounded basename.
     */
    public function validateFilename(string $filename): void
    {
        if (mb_strlen($filename) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1
            || str_contains($filename, '..')) {
            throw new InvalidArgumentException(
                'Rendered template filename must be a safe basename.',
            );
        }
    }
}
