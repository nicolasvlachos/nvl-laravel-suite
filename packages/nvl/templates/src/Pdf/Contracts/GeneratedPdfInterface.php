<?php

declare(strict_types=1);

namespace Nvl\Templates\Pdf\Contracts;

use Illuminate\Http\Response;

/**
 * Safe operations available on generated PDF bytes.
 */
interface GeneratedPdfInterface
{
    public function display(): Response;

    public function download(string $filename = 'document.pdf'): Response;

    public function save(string $path): string;

    public function getContent(): string;
}
