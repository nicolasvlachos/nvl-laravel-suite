<?php

declare(strict_types=1);

namespace Nvl\Seo\Support;

use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;

/**
 * Supplies image resolvers with fallback-aware values and their owning models.
 */
final readonly class SeoImageContext
{
    public function __construct(
        public SeoProfile $profile,
        public SeoProfileTranslation $translation,
        public string $locale,
        public ?string $url,
        public ?string $reference,
        public ?string $alt,
    ) {}
}
