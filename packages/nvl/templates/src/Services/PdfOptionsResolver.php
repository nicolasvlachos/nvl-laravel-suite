<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Contracts\View\Factory;
use InvalidArgumentException;
use Nvl\Templates\Data\PdfMargins;
use Nvl\Templates\Data\PdfOptions;
use Nvl\Templates\Enums\PdfOrientation;
use Nvl\Templates\Enums\PdfPageSize;
use Nvl\Templates\Rendering\ResolvedPdfOptions;
use Nvl\Templates\Rendering\TemplateRenderContext;

/**
 * Resolves and validates typed PDF options against package configuration.
 */
final readonly class PdfOptionsResolver
{
    public function __construct(
        private Factory $views,
        private TemplateContentGuard $contentGuard,
    ) {}

    /**
     * Resolve the complete mPDF option set for one render.
     */
    public function resolve(TemplateRenderContext $context): ResolvedPdfOptions
    {
        $configured = config('templates.pdf.defaults', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('templates.pdf.defaults must be an array.');
        }

        $allowed = [
            'page_size',
            'orientation',
            'margins',
            'default_font',
            'default_font_size',
            'dpi',
            'image_dpi',
            'image_quality',
            'show_image_errors',
            'title',
            'author',
            'creator',
            'subject',
            'keywords',
            'header_view',
            'footer_view',
            'watermark',
            'watermark_opacity',
            'compress',
            'pdfa',
            'pdfa_auto',
        ];
        $unknown = array_diff(array_keys($configured), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'templates.pdf.defaults contains unknown key ['.
                (string) reset($unknown).'].',
            );
        }

        $options = $context->pdf ?? new PdfOptions;
        $margins = $this->margins($options->margins, $configured['margins'] ?? []);
        $headerView = $this->view(
            $options->headerView ?? ($configured['header_view'] ?? null),
            'header_view',
        );
        $footerView = $this->view(
            $options->footerView ?? ($configured['footer_view'] ?? null),
            'footer_view',
        );
        $this->contentGuard->rendererOptions($options->headerData);
        $this->contentGuard->rendererOptions($options->footerData);
        $pdfa = $this->boolean($options->pdfa, $configured['pdfa'] ?? false, 'pdfa');
        $pdfaAuto = $this->boolean(
            $options->pdfaAuto,
            $configured['pdfa_auto'] ?? false,
            'pdfa_auto',
        );

        if ($pdfaAuto && ! $pdfa) {
            throw new InvalidArgumentException('PDF pdfa_auto requires pdfa to be enabled.');
        }

        $showImageErrors = $this->boolean(
            $options->showImageErrors,
            $configured['show_image_errors'] ?? false,
            'show_image_errors',
        );

        if ($showImageErrors
            && (! config('app.debug', false)
                || config('templates.pdf.allow_debug_image_errors', false) !== true)) {
            throw new InvalidArgumentException(
                'PDF image diagnostics require application debug mode and templates.pdf.allow_debug_image_errors.',
            );
        }

        return new ResolvedPdfOptions(
            pageSize: $this->pageSize(
                $options->pageSize ?? ($configured['page_size'] ?? PdfPageSize::A4->value),
            ),
            orientation: $this->orientation(
                $options->orientation
                    ?? ($configured['orientation'] ?? PdfOrientation::Portrait->value),
            ),
            margins: $margins,
            defaultFont: $this->font(
                $options->defaultFont ?? ($configured['default_font'] ?? 'dejavusans'),
            ),
            defaultFontSize: $this->number(
                $options->defaultFontSize ?? ($configured['default_font_size'] ?? 10),
                'default_font_size',
                6,
                72,
            ),
            dpi: $this->integer($options->dpi ?? ($configured['dpi'] ?? 96), 'dpi', 72, 300),
            imageDpi: $this->integer(
                $options->imageDpi ?? ($configured['image_dpi'] ?? 96),
                'image_dpi',
                72,
                600,
            ),
            imageQuality: $this->integer(
                $options->imageQuality ?? ($configured['image_quality'] ?? 85),
                'image_quality',
                1,
                100,
            ),
            showImageErrors: $showImageErrors,
            temporaryDirectory: $this->nullableString(
                $options->temporaryDirectory,
                'temporary_directory',
                4_096,
            ),
            title: $this->nullableString(
                $options->title ?? ($configured['title'] ?? null),
                'title',
                512,
            ),
            author: $this->string(
                $options->author ?? ($configured['author'] ?? ''),
                'author',
                512,
            ),
            creator: $this->string(
                $options->creator ?? ($configured['creator'] ?? 'NVL Templates'),
                'creator',
                512,
            ),
            subject: $this->nullableString(
                $options->subject ?? ($configured['subject'] ?? null),
                'subject',
                998,
            ),
            keywords: $this->string(
                $options->keywords ?? ($configured['keywords'] ?? ''),
                'keywords',
                2_000,
            ),
            headerView: $headerView,
            headerHtml: $this->nullableString(
                $options->headerHtml,
                'header_html',
                262_144,
            ),
            headerData: $options->headerData,
            footerView: $footerView,
            footerHtml: $this->nullableString(
                $options->footerHtml,
                'footer_html',
                262_144,
            ),
            footerData: $options->footerData,
            watermark: $this->nullableString(
                $options->watermark ?? ($configured['watermark'] ?? null),
                'watermark',
                255,
            ),
            watermarkOpacity: $this->number(
                $options->watermarkOpacity ?? ($configured['watermark_opacity'] ?? 0.1),
                'watermark_opacity',
                0,
                1,
            ),
            compress: $this->boolean(
                $options->compress,
                $configured['compress'] ?? true,
                'compress',
            ),
            pdfa: $pdfa,
            pdfaAuto: $pdfaAuto,
            protectionPermissions: $this->permissions($options->protectionPermissions),
            userPassword: $this->nullableString(
                $options->userPassword,
                'user_password',
                255,
            ),
            ownerPassword: $this->nullableString(
                $options->ownerPassword,
                'owner_password',
                255,
            ),
        );
    }

    private function pageSize(mixed $value): PdfPageSize
    {
        if ($value instanceof PdfPageSize) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('PDF page_size must be a string or enum.');
        }

        return PdfPageSize::tryFrom($value)
            ?? throw new InvalidArgumentException('PDF page_size is invalid.');
    }

    private function orientation(mixed $value): PdfOrientation
    {
        if ($value instanceof PdfOrientation) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('PDF orientation must be a string or enum.');
        }

        return PdfOrientation::tryFrom($value)
            ?? throw new InvalidArgumentException('PDF orientation is invalid.');
    }

    /**
     * @return array{left: float, right: float, top: float, bottom: float, header: float, footer: float}
     */
    private function margins(?PdfMargins $overrides, mixed $configured): array
    {
        if (! is_array($configured)) {
            throw new InvalidArgumentException('templates.pdf.defaults.margins must be an array.');
        }

        $unknown = array_diff(
            array_keys($configured),
            ['left', 'right', 'top', 'bottom', 'header', 'footer'],
        );

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'templates.pdf.defaults.margins contains an unknown key.',
            );
        }

        return [
            'left' => $this->number(($overrides === null ? null : $overrides->left) ?? ($configured['left'] ?? 15), 'margins.left', 0, 100),
            'right' => $this->number(($overrides === null ? null : $overrides->right) ?? ($configured['right'] ?? 15), 'margins.right', 0, 100),
            'top' => $this->number(($overrides === null ? null : $overrides->top) ?? ($configured['top'] ?? 16), 'margins.top', 0, 100),
            'bottom' => $this->number(($overrides === null ? null : $overrides->bottom) ?? ($configured['bottom'] ?? 16), 'margins.bottom', 0, 100),
            'header' => $this->number(($overrides === null ? null : $overrides->header) ?? ($configured['header'] ?? 8), 'margins.header', 0, 100),
            'footer' => $this->number(($overrides === null ? null : $overrides->footer) ?? ($configured['footer'] ?? 8), 'margins.footer', 0, 100),
        ];
    }

    private function font(mixed $value): string
    {
        $font = $this->string($value, 'default_font', 100);

        if (preg_match('/^[A-Za-z0-9_-]+$/', $font) !== 1) {
            throw new InvalidArgumentException('PDF default_font contains invalid characters.');
        }

        return $font;
    }

    private function number(
        mixed $value,
        string $key,
        float $minimum,
        float $maximum,
    ): float {
        if ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || $value < $minimum
            || $value > $maximum) {
            throw new InvalidArgumentException(
                "PDF {$key} must be between {$minimum} and {$maximum}.",
            );
        }

        return (float) $value;
    }

    private function integer(
        mixed $value,
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(
                "PDF {$key} must be an integer between {$minimum} and {$maximum}.",
            );
        }

        return $value;
    }

    private function string(mixed $value, string $key, int $maximum): string
    {
        if (! is_string($value)
            || ! mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > $maximum
            || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException(
                "PDF {$key} must be valid text of at most {$maximum} characters.",
            );
        }

        return $value;
    }

    private function nullableString(mixed $value, string $key, int $maximum): ?string
    {
        return $value === null ? null : $this->string($value, $key, $maximum);
    }

    private function boolean(mixed $override, mixed $configured, string $key): bool
    {
        $value = $override ?? $configured;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("PDF {$key} must be boolean.");
        }

        return $value;
    }

    private function view(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\\/-]*$/', $value) !== 1
            || str_contains($value, '..')
            || str_starts_with($value, '/')
            || ! $this->views->exists($value)) {
            throw new InvalidArgumentException("PDF {$key} must reference an existing safe view.");
        }

        return $value;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function permissions(array $permissions): array
    {
        $allowed = [
            'annot-forms',
            'assemble',
            'copy',
            'fill-forms',
            'modify',
            'print',
            'print-highres',
        ];

        foreach ($permissions as $permission) {
            if (! in_array($permission, $allowed, true)) {
                throw new InvalidArgumentException(
                    "PDF protection permission [{$permission}] is invalid.",
                );
            }
        }

        return array_values(array_unique($permissions));
    }
}
