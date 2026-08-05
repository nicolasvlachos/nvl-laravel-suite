<?php

declare(strict_types=1);

namespace Nvl\Templates\Pdf\Contracts;

use Nvl\Templates\Html\HtmlPayload;
use Nvl\Templates\Pdf\Options\PdfOptions;
use Nvl\Templates\Templates\Contracts\TemplateInterface;

/**
 * Renders HTML or class-based templates into verified PDF output.
 */
interface PdfServiceInterface
{
    public function renderHtml(
        HtmlPayload $payload,
        PdfOptions $options,
    ): GeneratedPdfInterface;

    public function saveHtml(
        HtmlPayload $payload,
        PdfOptions $options,
        string $path,
    ): string;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $assets
     * @param  array<string, mixed>  $options
     */
    public function renderTemplate(
        TemplateInterface $template,
        string $language,
        array $data = [],
        array $assets = [],
        array $options = [],
    ): GeneratedPdfInterface;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $assets
     * @param  array<string, mixed>  $options
     */
    public function saveWithTemplate(
        TemplateInterface $template,
        string $language,
        array $data = [],
        array $assets = [],
        array $options = [],
        ?string $filename = null,
    ): string;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $assets
     * @param  array<string, mixed>  $options
     */
    public function saveToStorage(
        TemplateInterface $template,
        string $language,
        array $data = [],
        array $assets = [],
        array $options = [],
        ?string $filename = null,
    ): string;
}
