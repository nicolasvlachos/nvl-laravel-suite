<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;

/** Typed Media authorization adapter. */
final readonly class ContentConsumerMediaAuthorization implements MediaAuthorization
{
    public function __construct(private ContentConsumerAccess $access) {}

    public function allows(
        MediaActorData $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        return $this->access->allowsMedia($actor);
    }
}
