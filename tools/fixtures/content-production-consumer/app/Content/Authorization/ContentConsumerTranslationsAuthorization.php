<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/** Typed Translations authorization adapter. */
final readonly class ContentConsumerTranslationsAuthorization implements TranslationsAuthorization
{
    public function __construct(private ContentConsumerAccess $access) {}

    public function authorize(
        TranslationsAbility $ability,
        ?TranslationEntry $entry = null,
    ): void {
        $this->access->authorizeManagement('translations');
    }
}
