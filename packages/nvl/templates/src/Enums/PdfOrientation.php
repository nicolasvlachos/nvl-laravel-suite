<?php

declare(strict_types=1);

namespace Nvl\Templates\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Page orientation supported by the bundled PDF renderer.
 */
#[TypeScript]
enum PdfOrientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';

    public function mpdfValue(): string
    {
        return $this === self::Portrait ? 'P' : 'L';
    }
}
