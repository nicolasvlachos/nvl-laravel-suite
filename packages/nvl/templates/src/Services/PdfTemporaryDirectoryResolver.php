<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Validates and creates the mPDF workspace without escaping allowed roots.
 */
final readonly class PdfTemporaryDirectoryResolver
{
    public function __construct(private SafeFilesystemPathResolver $paths) {}

    /**
     * Inspect the configured path without creating directories.
     */
    public function isSafe(): bool
    {
        try {
            $this->validatedPath(false);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Return a created, writable, and fully resolved mPDF directory.
     */
    public function resolve(?string $path = null): string
    {
        return $this->validatedPath(true, $path);
    }

    private function validatedPath(bool $create, ?string $override = null): string
    {
        $configured = $override ?? TemplatesConfiguration::string(
            'templates.pdf.temp_path',
            storage_path('framework/cache/nvl-templates/mpdf'),
        );
        $allowedRoots = config('templates.pdf.allowed_temp_roots', [storage_path()]);

        if (! is_array($allowedRoots) || $allowedRoots === []) {
            throw new InvalidArgumentException(
                'templates.pdf.allowed_temp_roots must contain at least one path.',
            );
        }

        return $this->paths->directory(
            $configured,
            array_values($allowedRoots),
            create: $create,
            writable: true,
            description: 'PDF temporary directory',
        );
    }
}
