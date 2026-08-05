<?php

declare(strict_types=1);

namespace Nvl\Content\Support;

use Illuminate\Http\Request;
use Nvl\Content\Data\ContentActorData;

/**
 * Maps Laravel authentication to the transport-neutral actor DTO.
 */
final class ContentActorFactory
{
    public function fromRequest(Request $request): ContentActorData
    {
        $user = $request->user();

        return $user === null
            ? new ContentActorData(null, null)
            : ContentActorData::fromAuthenticatable($user);
    }
}
