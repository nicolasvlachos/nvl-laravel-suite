<?php

declare(strict_types=1);

namespace Nvl\Seo\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Standard sitemap change-frequency hints.
 */
#[TypeScript]
enum SitemapChangeFrequency: string
{
    case Always = 'always';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Never = 'never';
}
