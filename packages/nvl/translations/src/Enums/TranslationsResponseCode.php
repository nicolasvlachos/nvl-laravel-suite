<?php

declare(strict_types=1);

namespace Nvl\Translations\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Stable opaque success discriminators emitted by the management API.
 */
enum TranslationsResponseCode: string implements ResponseCode
{
    case Updated = 'updated';
    case Imported = 'imported';
    case Exported = 'exported';
    case Scanned = 'scanned';
}
