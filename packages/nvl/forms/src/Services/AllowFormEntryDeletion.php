<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Contracts\FormEntryDeletionPolicy;
use Nvl\Forms\Models\FormEntry;

/**
 * Default deletion policy; applications may bind stricter legal-hold rules.
 */
final class AllowFormEntryDeletion implements FormEntryDeletionPolicy
{
    public function authorize(FormEntry $entry, ?Authenticatable $actor = null): void {}
}
