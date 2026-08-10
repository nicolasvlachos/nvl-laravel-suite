<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Actions\DeletePageAction;
use Nvl\Pages\Actions\GetNavigationAction;
use Nvl\Pages\Actions\ListPagesAction;
use Nvl\Pages\Actions\MovePageAction;
use Nvl\Pages\Actions\PreviewPageAction;
use Nvl\Pages\Actions\ResolvePageAction;
use Nvl\Pages\Actions\RestorePageAction;
use Nvl\Pages\Actions\UpdatePageAction;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Contracts\PageRequestContextResolver;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\Mutations\DeletePageData;
use Nvl\Pages\Data\Mutations\MovePageData;
use Nvl\Pages\Data\Mutations\RestorePageData;
use Nvl\Pages\Data\Mutations\UpdatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageAbility;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Exceptions\PageConflictException;
use Nvl\Pages\Exceptions\PageHierarchyException;
use Nvl\Pages\Exceptions\StalePageException;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Seo\PageSitemapSource;
use Nvl\Pages\Support\PagesRouteConfiguration;
use Nvl\Pages\Tests\Fixtures\RecordingPageAuthorization;
use Nvl\Pages\Tests\Fixtures\TestPageResource;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function createTestPage(
    string $key,
    string $slug,
    ?string $parentId = null,
    PageKind $kind = PageKind::Static,
    ?string $resource = null,
): Page {
    return app(CreatePageAction::class)->execute(
        new CreatePageData(
            key: $key,
            slug: $slug,
            parentId: $parentId,
            kind: $kind,
            resource: $resource,
            status: PageStatus::Published,
            translations: [
                'en' => [
                    'title' => ucfirst($slug),
                    'navigationLabel' => ucfirst($slug),
                ],
            ],
        ),
        PageActorData::system(),
    );
}

it('installs standalone with disabled routes and healthy diagnostics', function (): void {
    expect(Route::has('nvl.pages.public.resolve'))->toBeFalse()
        ->and(Route::has('nvl.pages.public.navigation'))->toBeFalse()
        ->and(Route::has('nvl.pages.management.index'))->toBeFalse()
        ->and(Route::has('nvl.pages.management.preview'))->toBeFalse()
        ->and(Route::has('nvl.pages.management.restore'))->toBeFalse()
        ->and(app(TranslationResourceRegistry::class)->keys())->toContain('pages.pages');

    $exit = Artisan::call('nvl:pages:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($report['healthy'])->toBeTrue()
        ->and($report['resources'])->toBe(['records.detail']);
});

it('rejects empty route middleware configuration', function (): void {
    config()->set('pages.routes.management.middleware', []);

    expect(fn (): array => PagesRouteConfiguration::middleware('management'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves a published static page with content and seo projections', function (): void {
    $page = createTestPage('pages.about', 'about');
    $resolved = app(ResolvePageAction::class)->execute(
        'about',
        'default',
        'en',
        PageActorData::anonymous(),
    );

    expect($resolved->page->id)->toBe($page->id)
        ->and($resolved->page->title)->toBe('About')
        ->and($resolved->page->titleLocale)->toBe('en')
        ->and($resolved->page->url)->toBe('https://pages.test/about')
        ->and($resolved->content->ownerId)->toBe($page->id)
        ->and($resolved->content->blocks)->toBe([])
        ->and($resolved->seo->locale)->toBe('en')
        ->and($resolved->resource)->toBeNull();
});

it('returns localized nested navigation without exposing management state', function (): void {
    $parent = createTestPage('pages.navigation', 'navigation');
    $child = createTestPage('pages.navigation-child', 'child', $parent->id);
    $navigation = app(GetNavigationAction::class)->execute(
        'default',
        'en',
        PageActorData::anonymous(),
    );

    expect($navigation->items)->toHaveCount(1)
        ->and($navigation->items[0]->id)->toBe($parent->id)
        ->and($navigation->items[0]->label)->toBe('Navigation')
        ->and($navigation->items[0]->children)->toHaveCount(1)
        ->and($navigation->items[0]->children[0]->id)->toBe($child->id);
});

it('previews drafts and restores deleted pages through explicit capabilities', function (): void {
    $draft = app(CreatePageAction::class)->execute(
        new CreatePageData(
            key: 'pages.draft',
            slug: 'draft',
            translations: ['en' => ['title' => 'Draft']],
        ),
        PageActorData::system(),
    );
    $preview = app(PreviewPageAction::class)->execute(
        'draft',
        'default',
        'en',
        PageActorData::system(),
    );

    expect($preview->page->id)->toBe($draft->id)
        ->and($preview->page->status)->toBe(PageStatus::Draft);

    $deleted = app(DeletePageAction::class)->execute(
        $draft,
        new DeletePageData($draft->revision),
        PageActorData::system(),
    );
    $restored = app(RestorePageAction::class)->execute(
        $draft->id,
        new RestorePageData($draft->revision),
        PageActorData::system(),
    );

    expect($deleted)->toBeTrue()
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->revision)->toBe($draft->revision + 1);
});

it('derives public site context from configuration and validates the locale', function (): void {
    $request = Request::create('/pages/about', 'GET', [
        'site' => 'untrusted',
        'locale' => 'bg',
    ]);
    $context = app(PageRequestContextResolver::class)->resolve($request);

    expect($context->site)->toBe('default')
        ->and($context->locale)->toBe('bg');
});

it('enforces the four-level hierarchy and rebuilds descendant paths', function (): void {
    $one = createTestPage('pages.one', 'one');
    $two = createTestPage('pages.two', 'two', $one->id);
    $three = createTestPage('pages.three', 'three', $two->id);
    $four = createTestPage('pages.four', 'four', $three->id);

    expect(fn (): Page => createTestPage('pages.five', 'five', $four->id))
        ->toThrow(PageHierarchyException::class);

    $moved = app(MovePageAction::class)->execute(
        $two,
        new MovePageData(null, 5, $two->revision),
        PageActorData::system(),
    );

    expect($moved->path)->toBe('two')
        ->and($three->refresh()->path)->toBe('two/three')
        ->and($four->refresh()->path)->toBe('two/three/four');
});

it('serializes tree mutations and authorizes only real lifecycle transitions', function (): void {
    $authorization = new RecordingPageAuthorization;
    app()->instance(PageAuthorization::class, $authorization);

    $page = createTestPage('pages.lifecycle', 'lifecycle');

    expect($authorization->abilities)->toBe([
        PageAbility::Create,
        PageAbility::Publish,
    ])->and(
        DB::table((string) config('pages.tables.page_tree_locks'))
            ->where('site', 'default')
            ->count(),
    )->toBe(1);

    $authorization->abilities = [];
    $updated = app(UpdatePageAction::class)->execute(
        $page,
        new UpdatePageData(
            slug: 'lifecycle',
            kind: PageKind::Static,
            resource: null,
            status: PageStatus::Published,
            expectedRevision: $page->revision,
            translations: ['en' => ['title' => 'Lifecycle updated']],
        ),
        PageActorData::system(),
    );

    expect($authorization->abilities)->toBe([PageAbility::Update]);

    $authorization->abilities = [];
    app(UpdatePageAction::class)->execute(
        $updated,
        new UpdatePageData(
            slug: 'lifecycle',
            kind: PageKind::Static,
            resource: null,
            status: PageStatus::Archived,
            expectedRevision: $updated->revision,
            translations: ['en' => ['title' => 'Lifecycle archived']],
        ),
        PageActorData::system(),
    );

    expect($authorization->abilities)->toBe([
        PageAbility::Update,
        PageAbility::Archive,
    ]);
});

it('rewrites descendant paths only when the canonical parent path changes', function (): void {
    $parent = createTestPage('pages.parent', 'parent');
    $child = createTestPage('pages.child', 'child', $parent->id);
    $childRevision = $child->revision;

    $parent = app(UpdatePageAction::class)->execute(
        $parent,
        new UpdatePageData(
            slug: 'parent',
            kind: PageKind::Static,
            resource: null,
            status: PageStatus::Published,
            expectedRevision: $parent->revision,
            translations: ['en' => ['title' => 'Renamed copy']],
        ),
        PageActorData::system(),
    );

    expect($child->refresh()->revision)->toBe($childRevision);

    app(UpdatePageAction::class)->execute(
        $parent,
        new UpdatePageData(
            slug: 'renamed-parent',
            kind: PageKind::Static,
            resource: null,
            status: PageStatus::Published,
            expectedRevision: $parent->revision,
            translations: ['en' => ['title' => 'Renamed path']],
        ),
        PageActorData::system(),
    );

    expect($child->refresh()->path)->toBe('renamed-parent/child')
        ->and($child->revision)->toBe($childRevision + 1);
});

it('rejects stale updates', function (): void {
    $page = createTestPage('pages.contact', 'contact');
    $payload = new UpdatePageData(
        slug: 'contact',
        kind: PageKind::Static,
        resource: null,
        status: PageStatus::Published,
        expectedRevision: $page->revision,
        translations: ['en' => ['title' => 'Contact']],
    );
    app(UpdatePageAction::class)->execute($page, $payload, PageActorData::system());

    expect(fn (): Page => app(UpdatePageAction::class)->execute(
        $page,
        $payload,
        PageActorData::system(),
    ))->toThrow(StalePageException::class);
});

it('translates unique database violations into stable page conflicts', function (): void {
    createTestPage('pages.unique', 'unique');

    expect(fn (): Page => createTestPage('pages.unique', 'another-slug'))
        ->toThrow(PageConflictException::class);
});

it('always scopes management lists to one explicit site', function (): void {
    $default = createTestPage('pages.default-site', 'default-site');
    app(CreatePageAction::class)->execute(
        new CreatePageData(
            key: 'pages.other-site',
            slug: 'other-site',
            site: 'other',
            status: PageStatus::Published,
            translations: ['en' => ['title' => 'Other site']],
        ),
        PageActorData::system(),
    );
    $pages = app(ListPagesAction::class)->execute(
        FilterSet::none(),
        'default',
        PageActorData::system(),
    );

    expect($pages->total())->toBe(1)
        ->and($pages->items()[0]->id)->toBe($default->id);
});

it('keeps localized navigation queries independent of page count', function (): void {
    $measure = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $navigation = app(GetNavigationAction::class)->execute(
            'default',
            'en',
            PageActorData::anonymous(),
        );
        $queryCount = count(DB::getQueryLog());
        $navigation->toArray();

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return $queryCount;
    };

    createTestPage('pages.query-1', 'query-1');
    $singleQueryCount = $measure();

    foreach (range(2, 25) as $index) {
        createTestPage("pages.query-{$index}", "query-{$index}");
    }

    $populatedQueryCount = $measure();

    expect($singleQueryCount)->toBeLessThanOrEqual(2)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

it('resolves only resources admitted by the handler query', function (): void {
    $page = createTestPage(
        'pages.records',
        'records',
        kind: PageKind::Resource,
        resource: 'records.detail',
    );
    $public = TestPageResource::query()->create(['name' => 'Visible', 'is_public' => true]);
    $private = TestPageResource::query()->create(['name' => 'Hidden', 'is_public' => false]);
    $resolved = app(ResolvePageAction::class)->execute(
        "records/{$public->id}",
        'default',
        'en',
        PageActorData::anonymous(),
    );

    expect($resolved->page->id)->toBe($page->id)
        ->and($resolved->resource?->id)->toBe($public->id)
        ->and($resolved->resource?->payload)->toBe(['name' => 'Visible']);

    expect(fn () => app(ResolvePageAction::class)->execute(
        "records/{$private->id}",
        'default',
        'en',
        PageActorData::anonymous(),
    ))->toThrow(NotFoundHttpException::class);

    expect(fn () => app(ResolvePageAction::class)->execute(
        'records/not-a-uuid',
        'default',
        'en',
        PageActorData::anonymous(),
    ))->toThrow(NotFoundHttpException::class);
});

it('yields sitemap ownership only to a matching SEO profile that can emit an entry', function (): void {
    $static = createTestPage('pages.info', 'info');
    createTestPage(
        'pages.records-map',
        'records-map',
        kind: PageKind::Resource,
        resource: 'records.detail',
    );
    $resource = TestPageResource::query()->create(['name' => 'Mapped', 'is_public' => true]);
    $entries = iterator_to_array(app(PageSitemapSource::class)->entries('default'));
    $urls = array_map(static fn ($entry): string => $entry->url, $entries);

    expect($urls)->toContain('https://pages.test/info')
        ->and($urls)->toContain("https://pages.test/records-map/{$resource->id}");

    app(SyncSeoProfileAction::class)->execute(
        $static,
        SeoProfilePayload::from([
            'translations' => ['en' => ['path' => '/other/info']],
        ]),
        'other',
    );
    $urls = array_map(
        static fn ($entry): string => $entry->url,
        iterator_to_array(app(PageSitemapSource::class)->entries('default')),
    );

    expect($urls)->toContain('https://pages.test/info');

    app(SyncSeoProfileAction::class)->execute(
        $static,
        SeoProfilePayload::from(['isIndexable' => true, 'sitemapIncluded' => true]),
        'default',
    );
    $urls = array_map(
        static fn ($entry): string => $entry->url,
        iterator_to_array(app(PageSitemapSource::class)->entries('default')),
    );

    expect($urls)->toContain('https://pages.test/info');

    app(SyncSeoProfileAction::class)->execute(
        $static,
        SeoProfilePayload::from([
            'translations' => ['en' => ['path' => '/info']],
        ]),
        'default',
    );
    $urls = array_map(
        static fn ($entry): string => $entry->url,
        iterator_to_array(app(PageSitemapSource::class)->entries('default')),
    );

    expect($urls)->not->toContain('https://pages.test/info')
        ->and($urls)->toContain("https://pages.test/records-map/{$resource->id}");
});
