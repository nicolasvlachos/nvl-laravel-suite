<?php

declare(strict_types=1);

namespace Nvl\Templates\Support\PdfConfig\Enums;

/**
 * Page formats accepted by the compatibility template surface.
 */
enum PaperSize: string
{
    case A4 = 'A4';
    case A3 = 'A3';
    case A5 = 'A5';
    case LETTER = 'Letter';
    case LEGAL = 'Legal';
}
