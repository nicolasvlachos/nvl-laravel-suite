<?php

declare(strict_types=1);

namespace App\Comments;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Pages\Models\Page;
use Nvl\Tenancy\Services\TenantBoundary;

/** Resolves a Page through its tenant-leading package query. */
final readonly class PageCommentTargetResolver implements CommentTargetResolver
{
    public function __construct(private TenantBoundary $boundary) {}

    public function alias(): string
    {
        return 'page';
    }

    public function resolve(string $identifier): ?Model
    {
        return $this->boundary->query(Page::query(), 'pages.pages')->find($identifier);
    }
}
