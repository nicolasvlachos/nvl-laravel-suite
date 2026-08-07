<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures;

use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Records management API authorization checks without imposing consumer policy.
 */
final class TranslationApiAuthorizationFake implements TranslationsAuthorization
{
    /**
     * @var list<TranslationsAbility>
     */
    public array $abilities = [];

    /**
     * Record one authorized management ability.
     */
    public function authorize(
        TranslationsAbility $ability,
        ?TranslationEntry $entry = null,
    ): void {
        $this->abilities[] = $ability;
    }
}
