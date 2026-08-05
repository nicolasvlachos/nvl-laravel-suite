<?php

declare(strict_types=1);

namespace App\Comments\Targets;

use App\Models\CommentsArticle;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentTargetResolver;

/**
 * Resolves allowlisted consumer articles while preserving exact key identity.
 */
final class ArticleCommentTargetResolver implements CommentTargetResolver
{
    /**
     * Return the API-safe alias owned by this resolver.
     */
    public function alias(): string
    {
        return 'article';
    }

    /**
     * Resolve a live article only when its canonical key exactly matches the request.
     */
    public function resolve(string $identifier): ?Model
    {
        $article = CommentsArticle::query()->find($identifier);

        if (! $article instanceof CommentsArticle
            || ! is_string($article->getKey())
            || ! hash_equals($identifier, $article->getKey())) {
            return null;
        }

        return $article;
    }
}
