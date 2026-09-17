<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Contracts\TenantSafeSeoImageResolver;
use Nvl\Seo\Data\SeoImage;
use Nvl\Seo\Support\SeoImageContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Resolves translation URLs and falls back to the configured site image.
 */
final readonly class DirectSeoImageResolver implements TenantSafeSeoImageResolver
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

    /** Direct URLs are safe, but this resolver cannot authorize model-backed references. */
    public function assertTenantSafe(SeoImageContext $context): void
    {
        if (is_string($context->reference) && $context->reference !== '') {
            throw new TenantBoundaryViolation(
                'The direct SEO image resolver cannot authorize a tenant Media reference.',
            );
        }
    }
}
