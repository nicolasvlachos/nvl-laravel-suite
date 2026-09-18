<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantContextMissing;

/**
 * Validates and creates the mPDF workspace without escaping allowed roots.
 */
final readonly class PdfTemporaryDirectoryResolver
{
    public function __construct(
        private SafeFilesystemPathResolver $paths,
        private TenantContext $context,
    ) {}

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
        $snapshot = $this->context->snapshot();

        if ((bool) config('tenancy.enabled', false)) {
            $scope = match ($snapshot->mode) {
                TenantContextMode::Tenant => 'tenant/'.$snapshot->tenantId?->value,
                TenantContextMode::Platform => 'platform',
                default => throw new TenantContextMissing,
            };
            $configured = rtrim($configured, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.$scope
                .DIRECTORY_SEPARATOR.'work'
                .DIRECTORY_SEPARATOR.Str::uuid();
        }
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
