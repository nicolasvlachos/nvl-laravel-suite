<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use InvalidArgumentException;
use Nvl\Seo\Support\HttpUrl;

/**
 * Resolved social-preview image independent of its storage backend.
 */
final readonly class SeoImage
{
    public function __construct(
        public string $url,
        public ?string $alt = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $mimeType = null,
    ) {
        if (! HttpUrl::isAbsolute($this->url)) {
            throw new InvalidArgumentException('An SEO image requires an absolute HTTP or HTTPS URL.');
        }

        if ($this->width !== null && $this->width <= 0) {
            throw new InvalidArgumentException('An SEO image width must be positive.');
        }

        if ($this->height !== null && $this->height <= 0) {
            throw new InvalidArgumentException('An SEO image height must be positive.');
        }

        if ($this->mimeType !== null && ! str_starts_with($this->mimeType, 'image/')) {
            throw new InvalidArgumentException('An SEO image MIME type must be an image type.');
        }
    }
}
