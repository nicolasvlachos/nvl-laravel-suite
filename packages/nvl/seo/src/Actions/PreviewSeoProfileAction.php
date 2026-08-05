<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Nvl\Seo\Data\ResolvedSeoData;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoMetadataResolver;

/**
 * Resolves the exact runtime metadata shown to a crawler or social client.
 */
final readonly class PreviewSeoProfileAction
{
    public function __construct(private SeoMetadataResolver $resolver) {}

    public function execute(SeoProfile $profile, ?string $locale = null): ResolvedSeoData
    {
        return $this->resolver->resolve($profile, $locale);
    }
}
