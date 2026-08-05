<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Gate-backed default that denies access until a consumer opts in.
 */
final class ConfiguredTranslationsAuthorization implements TranslationsAuthorization
{
    /**
     * Authorize through the consumer-configured Gate ability.
     */
    public function authorize(
        TranslationsAbility $ability,
        ?TranslationEntry $entry = null,
    ): void {
        $configuredAbility = config('translations.authorization.ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'Translations management requires an authorization binding or configured Gate ability.',
            );
        }

        $arguments = [$ability->value];

        if ($entry instanceof TranslationEntry) {
            $arguments[] = $entry;
        }

        Gate::authorize($configuredAbility, $arguments);
    }
}
