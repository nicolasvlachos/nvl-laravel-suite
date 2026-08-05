<?php

declare(strict_types=1);

namespace Nvl\Pages\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Nvl\Pages\Data\PageActorData;

/**
 * Converts Laravel request actors into the package's stable actor DTO.
 */
final class PageActorFactory
{
    /**
     * Build a stable page actor from one HTTP request.
     */
    public function fromRequest(Request $request): PageActorData
    {
        $actor = $request->user();

        return $actor instanceof Authenticatable
            ? PageActorData::fromAuthenticatable($actor)
            : PageActorData::anonymous();
    }
}
