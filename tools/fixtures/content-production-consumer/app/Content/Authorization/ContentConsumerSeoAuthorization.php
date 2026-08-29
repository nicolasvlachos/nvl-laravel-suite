<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Support\SeoAuthorizationContext;

/** Typed SEO authorization adapter. */
final readonly class ContentConsumerSeoAuthorization implements SeoAuthorization
{
    public function __construct(private ContentConsumerAccess $access) {}

    public function authorize(SeoAuthorizationContext $context): void
    {
        $this->access->authorizeManagement('seo');
    }
}
