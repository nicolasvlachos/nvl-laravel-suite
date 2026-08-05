<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Templates\Data\PdfMargins;
use Nvl\Templates\Data\PdfOptions;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Enums\PdfOrientation;
use Nvl\Templates\Enums\PdfPageSize;

/**
 * Converts source-controlled stored-definition options into typed core options.
 */
final class StoredTemplateOptionsFactory
{
    /**
     * @param  array<string, mixed>  $configured
     */
    public function make(
        string $renderer,
        string $locale,
        ?string $subject,
        array $configured,
    ): TemplateOptions {
        if ($renderer !== 'pdf') {
            return new TemplateOptions(
                renderer: $renderer,
                locale: $locale,
                subject: $subject,
                filename: $this->nullableString($configured['filename'] ?? null, 'filename'),
                rendererOptions: $configured,
            );
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
            'title',
            'author',
            'creator',
            'subject',
            'keywords',
            'header_view',
            'header_data',
            'footer_view',
            'footer_data',
            'watermark',
            'watermark_opacity',
            'compress',
            'pdfa',
            'pdfa_auto',
            'filename',
        ];
        $unknown = array_diff(array_keys($configured), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unsupported PDF renderer option ['.(string) reset($unknown).'].',
            );
        }

        return new TemplateOptions(
            renderer: $renderer,
            locale: $locale,
            subject: $subject,
            filename: $this->nullableString($configured['filename'] ?? null, 'filename'),
            pdf: new PdfOptions(
                pageSize: $this->pageSize($configured['page_size'] ?? null),
                orientation: $this->orientation($configured['orientation'] ?? null),
                margins: $this->margins($configured['margins'] ?? null),
                defaultFont: $this->nullableString(
                    $configured['default_font'] ?? null,
                    'default_font',
                ),
                defaultFontSize: $this->nullableFloat(
                    $configured['default_font_size'] ?? null,
                    'default_font_size',
                ),
                dpi: $this->nullableInteger($configured['dpi'] ?? null, 'dpi'),
                imageDpi: $this->nullableInteger(
                    $configured['image_dpi'] ?? null,
                    'image_dpi',
                ),
                imageQuality: $this->nullableInteger(
                    $configured['image_quality'] ?? null,
                    'image_quality',
                ),
                title: $this->nullableString($configured['title'] ?? null, 'title'),
                author: $this->nullableString($configured['author'] ?? null, 'author'),
                creator: $this->nullableString($configured['creator'] ?? null, 'creator'),
                subject: $this->nullableString($configured['subject'] ?? null, 'subject'),
                keywords: $this->nullableString(
                    $configured['keywords'] ?? null,
                    'keywords',
                ),
                headerView: $this->nullableString(
                    $configured['header_view'] ?? null,
                    'header_view',
                ),
                headerData: $this->map($configured['header_data'] ?? [], 'header_data'),
                footerView: $this->nullableString(
                    $configured['footer_view'] ?? null,
                    'footer_view',
                ),
                footerData: $this->map($configured['footer_data'] ?? [], 'footer_data'),
                watermark: $this->nullableString(
                    $configured['watermark'] ?? null,
                    'watermark',
                ),
                watermarkOpacity: $this->nullableFloat(
                    $configured['watermark_opacity'] ?? null,
                    'watermark_opacity',
                ),
                compress: $this->nullableBoolean(
                    $configured['compress'] ?? null,
                    'compress',
                ),
                pdfa: $this->nullableBoolean($configured['pdfa'] ?? null, 'pdfa'),
                pdfaAuto: $this->nullableBoolean(
                    $configured['pdfa_auto'] ?? null,
                    'pdfa_auto',
                ),
            ),
            rendererOptions: $configured,
        );
    }

    private function pageSize(mixed $value): ?PdfPageSize
    {
        if ($value === null || $value instanceof PdfPageSize) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('PDF page_size must be a string.');
        }

        return PdfPageSize::tryFrom($value)
            ?? throw new InvalidArgumentException('PDF page_size is invalid.');
    }

    private function orientation(mixed $value): ?PdfOrientation
    {
        if ($value === null || $value instanceof PdfOrientation) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('PDF orientation must be a string.');
        }

        return PdfOrientation::tryFrom($value)
            ?? throw new InvalidArgumentException('PDF orientation is invalid.');
    }

    private function margins(mixed $value): ?PdfMargins
    {
        if ($value === null || $value instanceof PdfMargins) {
            return $value;
        }

        $margins = $this->map($value, 'margins');
        $unknown = array_diff(
            array_keys($margins),
            ['left', 'right', 'top', 'bottom', 'header', 'footer'],
        );

        if ($unknown !== []) {
            throw new InvalidArgumentException('PDF margins contain an unknown key.');
        }

        return new PdfMargins(
            left: $this->nullableFloat($margins['left'] ?? null, 'margins.left'),
            right: $this->nullableFloat($margins['right'] ?? null, 'margins.right'),
            top: $this->nullableFloat($margins['top'] ?? null, 'margins.top'),
            bottom: $this->nullableFloat($margins['bottom'] ?? null, 'margins.bottom'),
            header: $this->nullableFloat($margins['header'] ?? null, 'margins.header'),
            footer: $this->nullableFloat($margins['footer'] ?? null, 'margins.footer'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value, string $key): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("PDF {$key} must be an associative object.");
        }

        $mapped = [];

        foreach ($value as $itemKey => $item) {
            if (! is_string($itemKey)) {
                throw new InvalidArgumentException("PDF {$key} must use string keys.");
            }

            $mapped[$itemKey] = $item;
        }

        return $mapped;
    }

    private function nullableString(mixed $value, string $key): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("PDF {$key} must be a string.");
        }

        return $value;
    }

    private function nullableInteger(mixed $value, string $key): ?int
    {
        if ($value !== null && ! is_int($value)) {
            throw new InvalidArgumentException("PDF {$key} must be an integer.");
        }

        return $value;
    }

    private function nullableFloat(mixed $value, string $key): ?float
    {
        if ($value === null) {
            return null;
        }

        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("PDF {$key} must be numeric.");
        }

        return (float) $value;
    }

    private function nullableBoolean(mixed $value, string $key): ?bool
    {
        if ($value !== null && ! is_bool($value)) {
            throw new InvalidArgumentException("PDF {$key} must be boolean.");
        }

        return $value;
    }
}
