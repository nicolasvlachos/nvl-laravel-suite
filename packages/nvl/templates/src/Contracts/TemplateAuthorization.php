<?php

declare(strict_types=1);

namespace Nvl\Templates\Contracts;

use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;

/**
 * Authorizes every template operation independently of an application's user model.
 */
interface TemplateAuthorization
{
    /**
     * Throw an authorization exception when the operation is forbidden.
     *
     * @param  array<string, mixed>  $context
     */
    public function authorize(
        TemplateAbility $ability,
        TemplateActorData $actor,
        array $context = [],
    ): void;
}
