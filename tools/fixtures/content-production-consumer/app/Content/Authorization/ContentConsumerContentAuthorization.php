<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;

/** Typed Content authorization adapter. */
final readonly class ContentConsumerContentAuthorization implements ContentAuthorization
{
    public function __construct(private ContentConsumerAccess $access) {}

    /** @param  array<string, mixed>  $context */
    public function authorize(
        ContentAbility $ability,
        ContentActorData $actor,
        ?ContentBlock $block = null,
        ?Model $owner = null,
        array $context = [],
    ): void {
        $this->access->authorizeContent($ability, $actor);
    }
}
