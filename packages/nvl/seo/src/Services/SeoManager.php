<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Nvl\Seo\Models\SeoProfile;

/**
 * Small public façade for resolving and rendering one page's metadata.
 */
final readonly class SeoManager
{
    public function __construct(
        private SeoMetadataResolver $resolver,
        private SeoHeadRenderer $renderer,
    ) {}

    public function for(
        Model|SeoProfile $owner,
        ?string $locale = null,
        ?string $scope = null,
    ): HtmlString {
        return $this->renderer->render(
            $this->resolver->resolve($owner, $locale, $scope),
        );
    }
}
