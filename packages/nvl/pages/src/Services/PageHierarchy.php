<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Support\Collection;
use Nvl\Pages\Exceptions\PageHierarchyException;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Support\PagesConfiguration;

/**
 * Validates page parents, cycles, subtree height, and the family-wide four-level cap.
 */
final class PageHierarchy
{
    /**
     * Validate a parent assignment while the caller holds the matching site tree lock.
     */
    public function assertValid(string $site, ?string $parentId, ?string $pageId = null): void
    {
        /** @var Collection<string, Page> $pages */
        $pages = Page::query()->where('site', $site)->get()->keyBy('id');
        $depth = 1;
        $visited = [];
        $cursor = $parentId !== null ? $pages->get($parentId) : null;

        if ($parentId !== null && ! $cursor instanceof Page) {
            throw new PageHierarchyException(
                "Parent [{$parentId}] does not belong to site [{$site}].",
            );
        }

        while ($cursor instanceof Page) {
            if ($cursor->id === $pageId || isset($visited[$cursor->id])) {
                throw new PageHierarchyException('A page hierarchy cannot contain a cycle.');
            }

            $visited[$cursor->id] = true;
            $depth++;
            $cursor = $cursor->parent_id !== null ? $pages->get($cursor->parent_id) : null;
        }

        $subtreeHeight = $pageId !== null
            ? $this->subtreeHeight($pages, $pageId)
            : 1;

        if ($depth + $subtreeHeight - 1 > PagesConfiguration::maximumDepth()) {
            throw new PageHierarchyException(
                'The page operation would exceed the configured hierarchy depth.',
            );
        }
    }

    /**
     * Build a canonical path while the caller holds the matching site tree lock.
     */
    public function path(string $site, ?string $parentId, string $slug): string
    {
        if ($parentId === null) {
            return $slug;
        }

        $parent = Page::query()
            ->where('site', $site)
            ->find($parentId);

        if (! $parent instanceof Page) {
            throw new PageHierarchyException(
                "Parent [{$parentId}] does not belong to site [{$site}].",
            );
        }

        return $parent->path.'/'.$slug;
    }

    /**
     * Recompute all descendant paths after a slug or parent change.
     *
     * @return list<Page>
     */
    public function rebuildDescendantPaths(Page $page): array
    {
        $changed = [];
        $children = Page::query()
            ->where('site', $page->site)
            ->where('parent_id', $page->id)
            ->lockForUpdate()
            ->get();

        foreach ($children as $child) {
            $path = $page->path.'/'.$child->slug;

            if ($child->path !== $path) {
                $child->path = $path;
                $child->save();
                $changed[] = $child;
            }

            array_push($changed, ...$this->rebuildDescendantPaths($child));
        }

        return $changed;
    }

    /**
     * @param  Collection<string, Page>  $pages
     */
    private function subtreeHeight(Collection $pages, string $pageId): int
    {
        $children = $pages->groupBy(
            static fn (Page $page): string => $page->parent_id ?? '__root__',
        );
        $height = function (string $parentId, array $visited = []) use (&$height, $children): int {
            if (isset($visited[$parentId])) {
                throw new PageHierarchyException('A page hierarchy cannot contain a cycle.');
            }

            $visited[$parentId] = true;
            $maximum = 1;

            foreach ($children->get($parentId, collect()) as $child) {
                $maximum = max($maximum, 1 + $height($child->id, $visited));
            }

            return $maximum;
        };

        return $height($pageId);
    }
}
