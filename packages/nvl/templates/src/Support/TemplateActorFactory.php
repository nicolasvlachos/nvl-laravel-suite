<?php

declare(strict_types=1);

namespace Nvl\Templates\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Nvl\Templates\Data\TemplateActorData;

/**
 * Maps Laravel authentication to the package's transport-neutral actor DTO.
 */
final class TemplateActorFactory
{
    /**
     * Build a stable actor identity from the authenticated request user.
     */
    public function fromRequest(Request $request): TemplateActorData
    {
        $user = $request->user();
        $identifier = $user?->getAuthIdentifier();

        return new TemplateActorData(
            type: $this->actorType($user),
            id: is_int($identifier) || is_string($identifier) ? (string) $identifier : null,
        );
    }

    private function actorType(mixed $user): ?string
    {
        if ($user instanceof Model) {
            return $user->getMorphClass();
        }

        return is_object($user) ? $user::class : null;
    }
}
