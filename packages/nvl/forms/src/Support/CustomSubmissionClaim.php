<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

use Nvl\Forms\Models\FormSubmissionReceipt;

/**
 * Result of atomically claiming a custom-handler submission.
 */
final readonly class CustomSubmissionClaim
{
    public function __construct(
        public FormSubmissionReceipt $receipt,
        public bool $isReplay,
    ) {}
}
