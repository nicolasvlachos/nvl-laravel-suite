<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Consumer-owned authorization boundary for management operations.
 */
interface TranslationsAuthorization
{
    /**
     * Authorize one management capability, optionally for a specific entry.
     */
    public function authorize(
        TranslationsAbility $ability,
        ?TranslationEntry $entry = null,
    ): void;
}
