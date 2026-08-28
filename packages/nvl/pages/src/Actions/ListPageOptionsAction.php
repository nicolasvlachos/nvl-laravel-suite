<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Data\PageOptionData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageIdentityGuard;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Lists bounded localized Page options for management consumers.
 */
final readonly class ListPageOptionsAction
{
    /**
     * Create the authorized Page option read.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageIdentityGuard $identities,
        private LocaleRegistry $locales,
    ) {}

    /**
     * Return deterministic minimal Page projections for one site.
     *
     * @return Collection<int, PageOptionData>
     */
    public function execute(
        string $site,
        string $locale,
        PageActorData $actor,
        ?string $search = null,
        int $limit = 50,
    ): Collection {
        $site = $this->identities->site($site);
        $locale = $this->locales->assertSupported($locale);
        $search = $this->identities->search($search);
        $this->authorization->authorize(
            PageAbility::List,
            $actor,
            context: new PageAuthorizationContextData(site: $site, locale: $locale),
        );

        if ($search !== null && mb_strlen($search) === 1) {
            return new Collection;
        }

        $maximum = min(
            100,
            PagesConfiguration::limit('maximum_page_options', 100),
        );
        $query = Page::query()
            ->select(['id', 'key', 'path', 'kind', 'status', 'revision'])
            ->where('site', $site)
            ->with('translations');

        if ($search !== null) {
            $pattern = "%{$search}%";
            $query->where(static function (Builder $query) use ($pattern): void {
                $query
                    ->whereLike('key', $pattern)
                    ->orWhereLike('path', $pattern)
                    ->orWhereHas('translations', static function (Builder $query) use ($pattern): void {
                        $query
                            ->whereLike('title', $pattern)
                            ->orWhereLike('navigation_label', $pattern);
                    });
            });
        }

        return $query
            ->orderBy('path')
            ->orderBy('id')
            ->limit(max(1, min($maximum, $limit)))
            ->get()
            ->map(
                static fn (Page $page): PageOptionData => PageOptionData::fromModel(
                    $page,
                    $locale,
                ),
            );
    }
}
