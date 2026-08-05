<?php

declare(strict_types=1);

namespace Nvl\Seo\Enums;

/**
 * Provides convenient schema.org types without restricting custom vocabulary terms.
 */
enum StructuredDataType: string
{
    case Article = 'Article';
    case BlogPosting = 'BlogPosting';
    case BreadcrumbList = 'BreadcrumbList';
    case CollectionPage = 'CollectionPage';
    case Event = 'Event';
    case FAQPage = 'FAQPage';
    case ImageObject = 'ImageObject';
    case LocalBusiness = 'LocalBusiness';
    case NewsArticle = 'NewsArticle';
    case Organization = 'Organization';
    case Person = 'Person';
    case Product = 'Product';
    case ProfilePage = 'ProfilePage';
    case VideoObject = 'VideoObject';
    case WebPage = 'WebPage';
    case WebSite = 'WebSite';
}
