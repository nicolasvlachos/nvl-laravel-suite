<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Pages\Data\PageResourceData;
use Nvl\Pages\Data\PageResourceRequestData;
use Nvl\Pages\Models\Page;
use Nvl\Seo\Data\SitemapEntry;

/**
 * Defines query, fetch, presentation, routing, and sitemap behavior for one resource page.
 *
 * @template TResource of Model
 */
interface PageResourceHandler
{
    /**
     * Return the stable configured handler alias.
     */
    public function alias(): string;

    /**
     * Relative route pattern appended to the owning page path, such as `{id}`.
     */
    public function routePattern(): string;

    /**
     * Return validation rules keyed exactly by route parameter name.
     *
     * @return array<string, mixed>
     */
    public function rules(Page $page): array;

    /**
     * Return a query with all publication, tenancy, ownership, and eager-load conditions applied.
     *
     * @return Builder<TResource>
     */
    public function query(PageResourceRequestData $request): Builder;

    /**
     * Fetch one resource from the already constrained query.
     *
     * @param  Builder<TResource>  $query
     * @return TResource|null
     */
    public function fetch(Builder $query, PageResourceRequestData $request): ?Model;

    /**
     * Convert one resolved resource to its sanitized transport projection.
     *
     * @param  TResource  $resource
     */
    public function present(Model $resource, PageResourceRequestData $request): PageResourceData;

    /**
     * Stream already absolute, canonical entries for this dynamic page.
     *
     * @return iterable<SitemapEntry>
     */
    public function sitemapEntries(Page $page, string $scope): iterable;
}
