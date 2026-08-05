<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Pages\Data\PageResourceRequestData;
use Nvl\Pages\Models\Page;
use Nvl\Seo\Data\SitemapEntry;

/**
 * Convenience base for handlers that fetch by their first route parameter.
 *
 * @template TResource of Model
 *
 * @implements PageResourceHandler<TResource>
 */
abstract class AbstractPageResourceHandler implements PageResourceHandler
{
    /**
     * Return bounded string validation for the first route parameter.
     *
     * @return array<string, mixed>
     */
    public function rules(Page $page): array
    {
        $parameter = $this->parameterNames()[0] ?? 'id';

        return [$parameter => ['required', 'string', 'max:255']];
    }

    /**
     * Fetch one resource by its model route key.
     *
     * @param  Builder<TResource>  $query
     * @return TResource|null
     */
    public function fetch(Builder $query, PageResourceRequestData $request): ?Model
    {
        $parameter = $this->parameterNames()[0] ?? 'id';
        $value = $request->parameters[$parameter] ?? null;

        if ($value === null) {
            return null;
        }

        return $query->where($query->getModel()->getRouteKeyName(), $value)->first();
    }

    /**
     * Return no sitemap entries unless a concrete handler opts in.
     *
     * @return iterable<SitemapEntry>
     */
    public function sitemapEntries(Page $page, string $scope): iterable
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private function parameterNames(): array
    {
        preg_match_all('/\\{([a-z][a-zA-Z0-9_]*)\\}/', $this->routePattern(), $matches);

        return array_values(array_filter($matches[1], 'is_string'));
    }
}
