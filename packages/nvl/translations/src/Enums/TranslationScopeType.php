<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

/**
 * Translation storage scope source.
 */
enum TranslationScopeType: string
{
    case App = 'app';
    case Module = 'module';
    case Vendor = 'vendor';
    case Custom = 'custom';
}
