<?php

declare(strict_types=1);

namespace App\Pages;

use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Pages\Contracts\AbstractPageResourceHandler;
use Nvl\Pages\Data\PageResourceData;
use Nvl\Pages\Data\PageResourceRequestData;
use Nvl\Pages\Models\Page;

/** @extends AbstractPageResourceHandler<Article> */
final class ArticlePageResourceHandler extends AbstractPageResourceHandler
{
    public function alias(): string
    {
        return 'articles.detail';
    }

    public function routePattern(): string
    {
        return '{slug}';
    }

    /** @return array<string, mixed> */
    public function rules(Page $page): array
    {
        return ['slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9-]+$/']];
    }

    /** @return Builder<Article> */
    public function query(PageResourceRequestData $request): Builder
    {
        return Article::query()->where('is_published', true);
    }

    public function present(
        Model $resource,
        PageResourceRequestData $request,
    ): PageResourceData {
        return new PageResourceData(
            type: $this->alias(),
            id: $resource->id,
            payload: [
                'slug' => $resource->getAttribute('slug'),
                'title' => $resource->getAttribute('title'),
            ],
        );
    }
}
