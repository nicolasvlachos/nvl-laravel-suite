<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;

/**
 * Secure default authorization adapter backed by an optional application callback.
 */
final class ConfiguredContentAuthorization implements ContentAuthorization
{
    public function authorize(
        ContentAbility $ability,
        ContentActorData $actor,
        ?ContentBlock $block = null,
        ?Model $owner = null,
        array $context = [],
    ): void {
        if ($actor->system) {
            return;
        }

        $callback = config('content.authorization.callback');
        $allowed = is_callable($callback)
            && $callback($ability, $actor, $block, $owner, $context) === true;

        if (! $allowed) {
            throw new AuthorizationException(
                "The actor is not authorized to [{$ability->value}] content.",
            );
        }
    }
}
