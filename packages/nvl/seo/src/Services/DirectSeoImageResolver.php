<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Contracts\SeoImageResolver;
use Nvl\Seo\Data\SeoImage;
use Nvl\Seo\Support\SeoImageContext;

/**
 * Resolves translation URLs and falls back to the configured site image.
 */
final readonly class DirectSeoImageResolver implements SeoImageResolver
{
    public function __construct(
        private AbsoluteUrl $urls,
    ) {}

    public function resolve(SeoImageContext $context): ?SeoImage
    {
        if ($context->url === '') {
            return null;
        }

        $url = $this->urls->resolve(
            $context->url
                ?? (is_string(config('seo.site.default_image_url'))
                    ? config('seo.site.default_image_url')
                    : null),
        );

        return $url !== null
            ? new SeoImage($url, $context->alt)
            : null;
    }
}
