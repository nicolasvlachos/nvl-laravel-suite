<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Contracts\FormEntryPrivacyPolicy;
use Nvl\Forms\Models\FormEntry;

/**
 * Default policy for action callers; HTTP access remains protected by policies.
 */
final class AllowFormEntryPrivacyOperations implements FormEntryPrivacyPolicy
{
    public function authorize(
        string $operation,
        FormEntry $entry,
        ?Authenticatable $actor = null,
    ): void {}
}
