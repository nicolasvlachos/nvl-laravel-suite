<?php

declare(strict_types=1);

namespace App\Comments\Authors;

use App\Comments\Http\CommentsConsumerActorResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Data\CommentAuthorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use RuntimeException;

/**
 * Resolves consumer authors in one batch and emits audience-scoped opaque keys.
 */
final class ApplicationCommentAuthorPresenter implements CommentAuthorPresenter
{
    /**
     * Present every comment author without exposing the stored actor identifier.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<string, CommentAuthorData|null>
     */
    public function present(Collection $comments, CommentAudience $audience): array
    {
        $emails = [];

        foreach ($comments as $comment) {
            if ($this->hasConsumerActor($comment)) {
                $emails[] = (string) $comment->actor_id;
            }
        }

        $users = User::query()
            ->whereIn('email', array_values(array_unique($emails)))
            ->get()
            ->keyBy('email');
        $presented = [];

        foreach ($comments as $comment) {
            if ($comment->trashed() || $comment->anonymized_at !== null) {
                $presented[$comment->id] = null;

                continue;
            }

            if ($comment->actor_type === null || $comment->actor_id === null) {
                $presented[$comment->id] = CommentAuthorData::anonymous();

                continue;
            }

            $user = $users->get($comment->actor_id);

            if (! $user instanceof User
                || ! hash_equals($user->email, $comment->actor_id)) {
                $presented[$comment->id] = CommentAuthorData::opaque();

                continue;
            }

            $presented[$comment->id] = new CommentAuthorData(
                key: $this->opaqueKey($audience, $user->email),
                displayName: $user->name,
                avatarUrl: null,
                label: $audience === CommentAudience::Management ? 'Consumer member' : null,
                anonymous: false,
            );
        }

        return $presented;
    }

    /**
     * Determine whether one comment contains the consumer actor namespace.
     */
    private function hasConsumerActor(Comment $comment): bool
    {
        return $comment->actor_type !== null
            && $comment->actor_id !== null
            && hash_equals(
                CommentsConsumerActorResolver::ACTOR_TYPE,
                $comment->actor_type,
            );
    }

    /**
     * Build a non-reversible key that cannot correlate authors across audiences.
     */
    private function opaqueKey(CommentAudience $audience, string $email): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The Comments author presenter requires a non-empty application key.');
        }

        return hash_hmac(
            'sha256',
            $audience->value."\0".$email,
            $key,
        );
    }
}
