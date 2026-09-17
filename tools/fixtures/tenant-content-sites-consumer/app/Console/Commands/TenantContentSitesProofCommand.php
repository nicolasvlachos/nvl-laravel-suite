<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Providers\TenantContentSitesServiceProvider as Fixture;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Facades\Content;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Seo\Actions\SyncSeoRedirectAction;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
use RuntimeException;

/** Proves two identical public graphs from a clean, no-dev archive consumer. */
final class TenantContentSitesProofCommand extends Command
{
    protected $signature = 'tenant-content-sites:proof';

    protected $description = 'Adopt and verify isolated tenant Content, Pages, and SEO publication';

    public function handle(TenantAdoptionCoordinator $coordinator, TenantRunner $runner): int
    {
        $operation = new PlatformOperation('consumer.adoption', 'release', 'fixture');
        $plan = $coordinator->prepare(['media', 'content', 'metafields', 'seo', 'pages'], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 100 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        if (! $done || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('The sealed publication graph did not verify.');
        }
        $coordinator->activate($plan, $operation);
        $runner->platform(
            new PlatformOperation('consumer.definitions', 'release', 'fixture'),
            static fn () => app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system()),
        );

        $a = $this->publish($runner, Fixture::A);
        $b = $this->publish($runner, Fixture::B);
        if ($a['tenant'] === $b['tenant'] || $a['page'] === $b['page'] || $a['cache'] === $b['cache']) {
            throw new RuntimeException('The sealed publication graph was not isolated.');
        }

        $this->line(json_encode(['a' => $a, 'b' => $b], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @return array{tenant:string,page:string,snapshot:string,cache:string} */
    private function publish(TenantRunner $runner, string $tenant): array
    {
        return $runner->run(new TenantId($tenant), fn (): array => $this->withSite($tenant, static function () use ($tenant): array {
            $page = app(CreatePageAction::class)->execute(new CreatePageData(
                key: 'pages.home',
                slug: 'home',
                status: PageStatus::Published,
                translations: ['en' => ['title' => 'Home '.$tenant]],
            ), PageActorData::system());
            $block = Content::createBlock(new CreateContentBlockData(
                definition: 'site.hero',
                key: 'hero',
                scope: 'site',
                scopeKey: 'default',
                translations: ['en' => ['title' => 'Hero '.$tenant]],
            ), ContentActorData::system());
            $block = Content::publishBlock($block, $block->revision, ContentActorData::system());
            Content::place($block, $page, 'content', new PlaceContentBlockData('hero'), ContentActorData::system());
            $snapshot = Content::capture($page, 'content', ContentActorData::system(), publishing: true);
            app(SyncSeoRedirectAction::class)->execute(null, new SeoRedirectPayload('/old-home', '/home'));

            return [
                'tenant' => $tenant,
                'page' => $page->id,
                'snapshot' => $snapshot->version,
                'cache' => app(SitemapCache::class)->key('default'),
            ];
        }));
    }

    private function withSite(string $tenant, Closure $callback): mixed
    {
        $previous = app('request');
        $site = Fixture::site($tenant);
        $request = Request::create($site->canonicalOrigin.'/proof');
        $request->attributes->set(TenantSiteContext::class, $site);
        app()->instance('request', $request);
        app()->instance(Request::class, $request);
        try {
            return $callback();
        } finally {
            app()->instance('request', $previous);
            app()->instance(Request::class, $previous);
        }
    }
}
