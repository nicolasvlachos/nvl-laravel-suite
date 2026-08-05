<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;

/**
 * Safe default authorization boundary: system callers only.
 *
 * Consumer applications should bind TemplateAuthorization to their policy adapter.
 */
final class ConfiguredTemplateAuthorization implements TemplateAuthorization
{
    /**
     * Authorize only explicitly trusted system actors by default.
     *
     * @param  array<string, mixed>  $context
     */
    public function authorize(
        TemplateAbility $ability,
        TemplateActorData $actor,
        array $context = [],
    ): void {
        if ($actor->system) {
            return;
        }

        throw new AuthorizationException(
            "Template ability [{$ability->value}] requires a consumer authorization binding.",
        );
    }
}
