<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Support\HttpUrl;
use Nvl\Seo\Support\SeoConfiguration;

/**
 * Resolves configured paths to absolute canonical URLs.
 */
final class AbsoluteUrl
{
    public function resolve(?string $urlOrPath): ?string
    {
        if ($urlOrPath === null || trim($urlOrPath) === '') {
            return null;
        }

        $urlOrPath = trim($urlOrPath);

        if (HttpUrl::isAbsolute($urlOrPath)) {
            return $urlOrPath;
        }

        $scheme = parse_url($urlOrPath, PHP_URL_SCHEME);

        if (is_string($scheme)) {
            return null;
        }

        $appUrl = config('app.url', 'http://localhost');
        $baseUrl = rtrim(SeoConfiguration::string(
            'seo.site.base_url',
            is_string($appUrl) ? $appUrl : 'http://localhost',
        ), '/');

        if (! HttpUrl::isBase($baseUrl)) {
            return null;
        }

        return $baseUrl.'/'.ltrim($urlOrPath, '/');
    }
}
