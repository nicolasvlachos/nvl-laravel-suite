<?php

declare(strict_types=1);

namespace App\Comments\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Data\CommentActorData;

/**
 * Maps the consumer's request-authenticated users to stable comment identities.
 */
final class CommentsConsumerActorResolver implements CommentActorResolver
{
    public const string ACTOR_TYPE = 'comments-consumer-user';

    /**
     * Resolve the exact authenticated consumer identity or the canonical anonymous actor.
     */
    public function fromRequest(Request $request): CommentActorData
    {
        $user = $request->user('comments_consumer');

        return $user instanceof User
            ? new CommentActorData(self::ACTOR_TYPE, $user->email)
            : CommentActorData::anonymous();
    }
}
