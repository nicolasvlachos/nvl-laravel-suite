<?php

declare(strict_types=1);

namespace Nvl\Pages\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageAuthorizationContextData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageChangeOperation;
use Nvl\Pages\Events\PageChanged;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageDatabaseConflict;
use Nvl\Pages\Services\PageHierarchy;
use Nvl\Pages\Services\PageLifecycle;
use Nvl\Pages\Services\PageMutationValues;
use Nvl\Pages\Services\PageTreeLock;
use Nvl\Pages\Support\PagesConfiguration;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Creates one page and its initial localized copy atomically.
 */
final readonly class CreatePageAction
{
    /**
     * Create the page creation action.
     */
    public function __construct(
        private PageAuthorization $authorization,
        private PageDatabaseConflict $conflicts,
        private PageHierarchy $hierarchy,
        private PageLifecycle $lifecycle,
        private PageMutationValues $values,
        private PageTreeLock $treeLock,
        private TranslationWriter $translations,
    ) {}

    /**
     * Create one page and its localized copy in a serialized site-tree transaction.
     */
    public function execute(CreatePageData $data, PageActorData $actor): Page
    {
        $context = new PageAuthorizationContextData(
            site: $data->site,
            parentId: $data->parentId,
        );
        $this->authorization->authorize(
            PageAbility::Create,
            $actor,
            context: $context,
        );
        $lifecycleAbility = $this->lifecycle->ability(null, $data->status);

        if ($lifecycleAbility instanceof PageAbility) {
            $this->authorization->authorize($lifecycleAbility, $actor, context: $context);
        }

        $this->values->assertKind($data->kind, $data->resource);
        $dates = $this->values->dates(
            $data->status,
            $data->publishedAt,
            $data->expiresAt,
        );

        try {
            return DB::connection(PagesConfiguration::connection())
                ->transaction(function () use ($actor, $data, $dates): Page {
                    $this->treeLock->acquire($data->site);
                    $this->hierarchy->assertValid($data->site, $data->parentId);
                    $page = Page::query()->create([
                        'parent_id' => $data->parentId,
                        'key' => $data->key,
                        'site' => $data->site,
                        'slug' => $data->slug,
                        'path' => $this->hierarchy->path(
                            $data->site,
                            $data->parentId,
                            $data->slug,
                        ),
                        'kind' => $data->kind,
                        'resource' => $data->resource,
                        'status' => $data->status,
                        'position' => $data->position,
                        'is_navigable' => $data->isNavigable,
                        'sitemap_included' => $data->sitemapIncluded,
                        'sitemap_priority' => $data->sitemapPriority,
                        'sitemap_change_frequency' => $data->sitemapChangeFrequency,
                        'published_at' => $dates['published_at'],
                        'expires_at' => $dates['expires_at'],
                    ]);
                    $this->translations->replace(
                        $page,
                        $this->values->translations($data->translations),
                    );
                    PageChanged::dispatch(
                        $page->id,
                        $page->site,
                        PageChangeOperation::Created,
                        $page->revision,
                        $actor,
                        [$page->id],
                    );

                    return $page->refresh()->load('translations');
                }, attempts: PagesConfiguration::transactionAttempts());
        } catch (QueryException $exception) {
            $this->conflicts->rethrow($exception);
        }
    }
}
