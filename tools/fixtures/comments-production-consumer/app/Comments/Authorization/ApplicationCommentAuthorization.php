<?php

declare(strict_types=1);

namespace App\Comments\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;

/**
 * Adds explicit consumer moderation roles to the package's conservative member policy.
 */
final readonly class ApplicationCommentAuthorization implements CommentAuthorization, CommentQueryScope
{
    public const string MODERATOR = 'moderator@comments-consumer.test';

    public const string IDENTITY_AUDITOR = 'identity-auditor@comments-consumer.test';

    /**
     * Create the consumer policy around the standalone package defaults.
     */
    public function __construct(
        private ConfiguredCommentAuthorization $defaults,
    ) {}

    /**
     * Determine whether the consumer actor may perform one comment operation.
     *
     * @param  array<string, mixed>  $context
     */
    public function allows(
        CommentAbility $ability,
        CommentActorData $actor,
        ?Comment $comment = null,
        ?Model $target = null,
        CommentAudience $audience = CommentAudience::Public,
        array $context = [],
    ): bool {
        if ($audience === CommentAudience::Management && $this->isManager($actor)) {
            if ($ability === CommentAbility::ViewIdentity) {
                return $this->isIdentityAuditor($actor);
            }

            return true;
        }

        return $this->defaults->allows(
            $ability,
            $actor,
            $comment,
            $target,
            $audience,
            $context,
        );
    }

    /**
     * Apply package-default public and member scopes while opening management only to managers.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeComments(
        Builder $query,
        CommentActorData $actor,
        Model $target,
        CommentAudience $audience,
        CommentAbility $ability,
    ): void {
        if ($audience === CommentAudience::Management && $this->isManager($actor)) {
            return;
        }

        $this->defaults->scopeComments($query, $actor, $target, $audience, $ability);
    }

    /**
     * Determine whether the actor belongs to either consumer management role.
     */
    private function isManager(CommentActorData $actor): bool
    {
        return $actor->id !== null
            && ($this->matches($actor->id, self::MODERATOR)
                || $this->matches($actor->id, self::IDENTITY_AUDITOR));
    }

    /**
     * Determine whether the manager has the separate identity-view permission.
     */
    private function isIdentityAuditor(CommentActorData $actor): bool
    {
        return $actor->id !== null
            && $this->matches($actor->id, self::IDENTITY_AUDITOR);
    }

    /**
     * Compare one actor identifier without normalization or collation semantics.
     */
    private function matches(string $actual, string $expected): bool
    {
        return hash_equals($expected, $actual);
    }
}
