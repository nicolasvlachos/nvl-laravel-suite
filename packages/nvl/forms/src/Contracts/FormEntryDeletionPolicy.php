<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Models\FormEntry;

/**
 * Application-owned entry deletion and legal-hold policy.
 */
interface FormEntryDeletionPolicy
{
    /**
     * Throw when an entry cannot be deleted.
     */
    public function authorize(FormEntry $entry, ?Authenticatable $actor = null): void;
}
