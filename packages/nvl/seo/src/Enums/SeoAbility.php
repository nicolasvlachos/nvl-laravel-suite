<?php

declare(strict_types=1);

namespace Nvl\Seo\Enums;

/**
 * Stable management capabilities passed to consumer authorization.
 */
enum SeoAbility: string
{
    case List = 'list';
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Duplicate = 'duplicate';
    case Archive = 'archive';
    case Delete = 'delete';
    case Preview = 'preview';
    case ManageRedirects = 'manage_redirects';
    case Import = 'import';
}
