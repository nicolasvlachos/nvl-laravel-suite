<?php

declare(strict_types=1);

namespace App\Comments\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Nvl\Comments\Models\Comment;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\DefaultMediaAuthorization;

/**
 * Extends uploader ownership with public delivery for approved comment attachments.
 */
final readonly class ApplicationMediaAuthorization implements MediaAuthorization
{
    /**
     * Create the consumer policy around Media's uploader-ownership defaults.
     */
    public function __construct(
        private DefaultMediaAuthorization $defaults,
    ) {}

    /**
     * Authorize uploader mutations and audience-safe delivery from live public comments.
     */
    public function allows(
        MediaActorData $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        if ($owner instanceof Comment
            && in_array($ability, [MediaAbility::View, MediaAbility::Download], true)
            && $owner->status === CommentStatus::Approved
            && $owner->visibility === CommentVisibility::Public
            && ! $owner->trashed()
            && $owner->anonymized_at === null) {
            return true;
        }

        return $this->defaults->allows($actor, $ability, $media, $owner);
    }
}
