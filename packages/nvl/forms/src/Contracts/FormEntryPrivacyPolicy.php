<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Models\FormEntry;

/**
 * Authorizes privacy and retention operations on stored form entries.
 */
interface FormEntryPrivacyPolicy
{
    public function authorize(
        string $operation,
        FormEntry $entry,
        ?Authenticatable $actor = null,
    ): void;
}
