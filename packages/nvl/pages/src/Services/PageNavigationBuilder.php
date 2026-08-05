<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Support\Collection;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Data\NavigationItemData;
use Nvl\Pages\Models\Page;

/**
 * Builds a localized navigation tree from ordered visible static pages.
 */
final readonly class PageNavigationBuilder
{
    /**
     * Create the localized navigation tree builder.
     */
    public function __construct(private PageUrlGenerator $urls) {}

    /**
     * Build navigation nodes while excluding children of hidden or missing ancestors.
     *
     * @param  Collection<int, Page>  $pages
     * @return list<NavigationItemData>
     */
    public function build(Collection $pages, string $locale): array
    {
        $navigable = $pages->filter(
            static fn (Page $page): bool => $page->is_navigable,
        );
        $byParent = $navigable->groupBy(
            static fn (Page $page): string => $page->parent_id ?? '__root__',
        );
        $knownIds = $pages->keyBy('id');

        return $this->children($byParent, $knownIds, '__root__', $locale);
    }

    /**
     * Build one level of navigation nodes recursively.
     *
     * @param  Collection<string, Collection<int, Page>>  $byParent
     * @param  Collection<string, Page>  $knownIds
     * @return list<NavigationItemData>
     */
    private function children(
        Collection $byParent,
        Collection $knownIds,
        string $parentId,
        string $locale,
    ): array {
        $items = [];

        foreach ($byParent->get($parentId, new Collection) as $page) {
            if ($page->parent_id !== null
                && ! $knownIds->get($page->parent_id) instanceof Page) {
                continue;
            }

            $label = $page->translated('navigation_label', $locale);

            if (! is_string($label) || $label === '') {
                $label = $page->displayTitle($locale);
            }

            $items[] = new NavigationItemData(
                id: $page->id,
                key: $page->key,
                path: $page->path,
                url: $this->urls->url($page, $locale),
                label: $label,
                children: $this->children($byParent, $knownIds, $page->id, $locale),
            );
        }

        return $items;
    }
}
