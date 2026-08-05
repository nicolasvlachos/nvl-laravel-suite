<?php

declare(strict_types=1);

namespace Nvl\Pages\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Pages\Contracts\AbstractPageResourceHandler;
use Nvl\Pages\Data\PageResourceData;
use Nvl\Pages\Data\PageResourceRequestData;
use Nvl\Pages\Models\Page;
use Nvl\Seo\Data\SitemapEntry;

/**
 * Demonstrates query-constrained fetching and explicit public projection.
 *
 * @extends AbstractPageResourceHandler<TestPageResource>
 */
final class TestPageResourceHandler extends AbstractPageResourceHandler
{
    public function alias(): string
    {
        return 'records.detail';
    }

    public function routePattern(): string
    {
        return '{id}';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(Page $page): array
    {
        return ['id' => ['required', 'uuid']];
    }

    /**
     * @return Builder<TestPageResource>
     */
    public function query(PageResourceRequestData $request): Builder
    {
        return TestPageResource::query()->where('is_public', true);
    }

    public function present(
        Model $resource,
        PageResourceRequestData $request,
    ): PageResourceData {
        return new PageResourceData(
            type: $this->alias(),
            id: $resource->id,
            payload: ['name' => $resource->name],
        );
    }

    /**
     * @return iterable<SitemapEntry>
     */
    public function sitemapEntries(Page $page, string $scope): iterable
    {
        foreach (TestPageResource::query()->where('is_public', true)->lazyById() as $resource) {
            yield new SitemapEntry(
                url: 'https://pages.test/'.$page->path.'/'.$resource->id,
                lastModified: $resource->updated_at,
            );
        }
    }
}
