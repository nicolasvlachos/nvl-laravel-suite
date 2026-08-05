<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

/**
 * Translation file format.
 */
enum TranslationFormat: string
{
    case Php = 'php';
    case Json = 'json';
}
