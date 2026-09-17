<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Facades\Content;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Tests\Fixtures\TenantScenario;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Actions\SyncSeoRedirectAction;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Data\Mutations\SeoRedirectPayload;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

it('publishes one isolated Page Content Metafield SEO and sitemap graph', function (): void {
    Storage::fake('local');
    $scenario = TenantScenario::install();

    [$page, $snapshot, $media] = $scenario->runWithSite(TenantScenario::A, static function (): array {
        $page = app(CreatePageAction::class)->execute(new CreatePageData(
            key: 'pages.home',
            slug: 'home',
            status: PageStatus::Published,
            translations: ['en' => ['title' => 'Tenant A home']],
        ), PageActorData::system());
        $media = Media::factory()->create([
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'type' => MediaType::IMAGE,
            'is_public' => true,
            'visibility' => MediaVisibility::Public,
            'status' => MediaLifecycleStatus::Available,
        ]);
        Storage::disk('local')->put(app(MediaPathResolver::class)->mediaPath($media), 'image');
        $block = Content::createBlock(new CreateContentBlockData(
            definition: 'hero',
            key: 'home-hero',
            scope: 'site',
            scopeKey: 'default',
            translations: ['en' => ['title' => 'Welcome A']],
        ), ContentActorData::system());
        $block = Content::publishBlock($block, $block->revision, ContentActorData::system());
        Content::place($block, $page, 'content', new PlaceContentBlockData(
            key: 'hero',
            overrides: ['image' => $media->id],
        ), ContentActorData::system());

        $definition = app(CreateMetafieldDefinitionAction::class)->execute(
            CreateMetafieldDefinitionPayload::from([
                'namespace' => 'page',
                'key' => 'theme',
                'type' => 'string',
                'assignment' => ['ownerType' => 'page', 'section' => 'general'],
                'translations' => ['en' => ['title' => 'Theme']],
            ]),
        );
        app(SetMetafieldAction::class)->execute($page, $definition->handle, 'tenant-a');
        app(SyncSeoProfileAction::class)->execute($page, SeoProfilePayload::from([
            'translations' => ['en' => ['path' => '/home', 'title' => 'Tenant A home']],
        ]));
        app(SyncSeoRedirectAction::class)->execute(null, new SeoRedirectPayload('/old-home', '/home'));

        return [$page, Content::capture($page, 'content', ContentActorData::system(), publishing: true), $media];
    });

    $xml = $scenario->runWithSite(TenantScenario::A, static fn () => app(SitemapGenerator::class)->generate('default'));

    expect($snapshot->tenantId)->toBe(TenantScenario::A)
        ->and($snapshot->blocks[0]->overrides['image'])->toBe($media->id)
        ->and($xml)->toContain('https://a.pages.test/home')
        ->and($xml)->not->toContain('b.pages.test');

    $foreignMedia = $scenario->runWithSite(TenantScenario::B, static fn () => Media::factory()->create());
    $scenario->runWithSite(TenantScenario::A, static function () use ($foreignMedia, $page): void {
        $block = Content::createBlock(new CreateContentBlockData(
            definition: 'hero',
            key: 'foreign-media-attempt',
            scope: 'site',
            scopeKey: 'default',
            translations: ['en' => ['title' => 'Denied']],
        ), ContentActorData::system());
        $block = Content::publishBlock($block, $block->revision, ContentActorData::system());

        expect(fn () => Content::place(
            $block,
            $page,
            'content',
            new PlaceContentBlockData('foreign', overrides: ['image' => $foreignMedia->id]),
            ContentActorData::system(),
        ))->toThrow(TenantBoundaryViolation::class);
    });
});
