<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

/**
 * Lifecycle states for a custom-handler submission receipt.
 */
enum FormSubmissionReceiptState: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
