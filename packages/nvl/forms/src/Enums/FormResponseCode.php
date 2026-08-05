<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Catalog of response codes for the Forms module.
 *
 * Each case value is a short, opaque discriminator emitted by controllers in
 * `flash.message`. The frontend `useAction` hook does NOT translate these —
 * the action declares its own `messages.success` / `messages.error` via typed
 * `t()` calls.
 */
enum FormResponseCode: string implements ResponseCode
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Duplicated = 'duplicated';

    case CreateFailed = 'create_failed';
    case UpdateFailed = 'update_failed';
    case DeleteFailed = 'delete_failed';
    case OperationFailed = 'operation_failed';
}
