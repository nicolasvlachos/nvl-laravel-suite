<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Content\Actions\ArchiveContentBlockAction;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\DeleteContentBlockAction;
use Nvl\Content\Actions\DeleteContentPlacementAction;
use Nvl\Content\Actions\GetContentBlockAction;
use Nvl\Content\Actions\ListContentBlocksAction;
use Nvl\Content\Actions\ListContentDefinitionsAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\RestoreContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Actions\UpdateContentBlockAction;
use Nvl\Content\Actions\UpdateContentPlacementAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentCompositionSnapshotBlockData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\ContentFieldDefinitionData;
use Nvl\Content\Data\ContentSchemaData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentPlacementData;
use Nvl\Content\Data\RenderedContentBannerData;
use Nvl\Content\Data\RenderedContentButtonData;
use Nvl\Content\Data\RenderedContentHeadingData;
use Nvl\Content\Data\RenderedContentImageData;
use Nvl\Content\Data\RenderedPrivateMediaData;
use Nvl\Content\Data\RenderedRichTextData;
use Nvl\Content\Enums\ContentAlignment;
use Nvl\Content\Enums\ContentHeadingLevel;
use Nvl\Content\Enums\ContentLinkRelationship;
use Nvl\Content\Enums\ContentMutationMode;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Facades\Content as ContentFacade;
use Nvl\Content\Http\ContentResponseData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Models\ContentRevision;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionLoader;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentPayloadGuard;
use Nvl\Content\Services\ContentReferenceRegistry;
use Nvl\Content\Services\ContentRenderer;
use Nvl\Content\Services\ContentSnapshotService;
use Nvl\Content\Support\ContentRouteConfiguration;
use Nvl\Content\Tests\Fixtures\TestContentOwner;
use Nvl\Content\Tests\Fixtures\UnsafeReferenceResolver;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Media\Data\Display\PublicMedia;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Http\Controllers\MediaAssetController;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaPathResolver;

beforeEach(function (): void {
    Storage::fake('public');
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

it('provides the complete revision-safe block lifecycle through the facade', function (): void {
    $actor = ContentActorData::system();
    $block = ContentFacade::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'facade-lifecycle',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Facade lifecycle']],
        ),
        $actor,
    );
    $updated = ContentFacade::updateBlock(
        $block,
        new UpdateContentBlockData(
            expectedRevision: $block->revision,
            metadata: ['source' => 'facade'],
        ),
        $actor,
    );
    $published = ContentFacade::publishBlock($updated, $updated->revision, $actor);
    $archived = ContentFacade::archiveBlock($published, $published->revision, $actor);

    ContentFacade::deleteBlock($archived, $archived->revision, $actor);

    $deleted = ContentBlock::withTrashed()->findOrFail($archived->id);
    $restored = ContentFacade::restoreBlock($deleted, $deleted->revision, $actor);

    expect($restored->status)->toBe(ContentStatus::Draft)
        ->and($restored->revision)->toBe(6)
        ->and($restored->metadata)->toBe(['source' => 'facade'])
        ->and($restored->trashed())->toBeFalse();
});

it('creates publishes places and renders a sanitized translatable composition', function (): void {
    $media = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
    Storage::disk('public')->put(app(MediaPathResolver::class)->mediaPath($media), 'image');
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'homepage-hero',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'image' => $media->id,
                'links' => [
                    ['label' => 'Visit', 'url' => 'https://example.com'],
                ],
                'layout' => ['columns' => 2],
                'metrics' => [
                    ['label' => 'Conversion', 'value' => 4.2],
                ],
                'article' => 'article-1',
            ],
            translations: [
                'en' => [
                    'title' => 'Welcome',
                    'body' => '<p>Hello</p><script>alert(1)</script>',
                ],
                'bg' => ['title' => 'Добре дошли'],
            ],
        ),
        ContentActorData::system(),
    );

    expect($block->status)->toBe(ContentStatus::Draft)
        ->and($block->definition_schema->fieldCount())->toBe(12)
        ->and($block->values['links'][0]['_key'])->toBeString()
        ->and(MediaAssociation::query()->where('associable_id', $block->id)->count())->toBe(1);

    $published = app(PublishContentBlockAction::class)->execute(
        $block,
        $block->revision,
        ContentActorData::system(),
    );
    $owner = TestContentOwner::query()->create(['name' => 'Homepage']);
    app(PlaceContentBlockAction::class)->execute(
        $published,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'hero',
            region: 'main',
        ),
        ContentActorData::system(),
    );
    $composition = app(ContentRenderer::class)->render(
        $owner,
        'default',
        'en',
        ContentActorData::system(),
    );
    $rendered = $composition->blocks[0];
    config()->set([
        'content.locales.available' => ['en', 'bg', 'de'],
        'translatable.locales' => ['en', 'bg', 'de'],
        'translatable.fallback_locales' => ['bg'],
        'translatable.default_locale' => 'en',
    ]);
    $snapshot = app(ContentSnapshotService::class)->capture(
        $owner,
        'default',
        ContentActorData::system(),
    );
    $snapshotFallback = app(ContentSnapshotService::class)->render(
        $snapshot,
        'de',
        ContentActorData::system(),
    );
    $liveFallback = app(ContentRenderer::class)->render(
        $owner,
        'default',
        'de',
        ContentActorData::system(),
    );

    expect($published->status)->toBe(ContentStatus::Published)
        ->and($rendered->values['title'])->toBe('Welcome')
        ->and($rendered->values['body'])->toBeInstanceOf(RenderedRichTextData::class)
        ->and($rendered->values['body']->html)->not->toContain('<script')
        ->and($rendered->values['article']['title'])->toBe('Article (en)')
        ->and($rendered->values['article']['owner_id'])->toBe($owner->id)
        ->and($rendered->values['article']['group'])->toBe('default')
        ->and($rendered->values['article']['public_only'])->toBeTrue()
        ->and($composition->regions['main'][0]->id)->toBe($block->id)
        ->and($composition->version)->toHaveLength(64)
        ->and($snapshotFallback->value('hero.title'))->toBe('Добре дошли')
        ->and($liveFallback->value('hero.title'))->toBe('Добре дошли');

    $html = Blade::render(
        '<x-nvl-content::composition :composition="$composition" />',
        ['composition' => $composition],
    );

    expect($html)->toContain('Welcome')
        ->and($html)->toContain('<p>Hello</p>')
        ->and($html)->toContain('<img')
        ->and($html)->toContain('<table')
        ->and($html)->not->toContain('<script');
});

it('compiles rich semantic presets and deeply localizes their typed values', function (): void {
    $actor = ContentActorData::system();
    $definition = new ContentDefinitionSource(
        key: 'homepage-banner',
        name: 'Homepage banner',
        description: 'A reusable typed homepage banner.',
        category: 'marketing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'banner',
                'preset' => 'banner',
                'label' => 'Banner',
                'required' => true,
            ]],
        ],
        allowedScopes: ['site'],
    );
    app(ContentDefinitionRegistry::class)->register($definition);
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $compiled = app(ContentDefinitionRegistry::class)->get('homepage-banner');
    $presets = ContentFacade::presets($actor);
    $media = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
    Storage::disk('public')->put(app(MediaPathResolver::class)->mediaPath($media), 'image');
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'homepage-banner',
            key: 'homepage-banner',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'banner' => [
                    'image' => [
                        'media' => $media->id,
                        'decorative' => false,
                        'focal_x' => 0.4,
                        'focal_y' => 0.6,
                    ],
                    'primary_action' => [
                        'href' => '/donate',
                        'target' => '_blank',
                        'rel' => ['nofollow'],
                    ],
                    'alignment' => 'center',
                ],
            ],
            translations: [
                'en' => [
                    'banner' => [
                        'heading' => [
                            'eyebrow' => 'Our mission',
                            'title' => 'Help today',
                            'description' => '<p>Make a lasting difference.</p>',
                        ],
                        'image' => [
                            'alt' => 'Volunteers preparing donations',
                            'caption' => '<p>Our volunteer team.</p>',
                        ],
                        'primary_action' => [
                            'label' => 'Donate now',
                            'title' => 'Donate to our mission',
                        ],
                    ],
                ],
                'bg' => [
                    'banner' => [
                        'heading' => ['title' => 'Помогнете днес'],
                        'image' => ['alt' => null],
                        'primary_action' => ['label' => 'Дарете сега'],
                    ],
                ],
            ],
        ),
        $actor,
    );
    $published = app(PublishContentBlockAction::class)->execute(
        $block,
        $block->revision,
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Rich homepage']);
    app(PlaceContentBlockAction::class)->execute(
        $published,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'banner'),
        $actor,
    );
    $live = app(ContentRenderer::class)->render($owner, 'homepage', 'bg', $actor);
    $snapshot = app(ContentSnapshotService::class)->capture($owner, 'homepage', $actor);
    $hydratedSnapshot = ContentCompositionSnapshotData::from($snapshot->toArray());
    $snapshotRender = app(ContentSnapshotService::class)->render($snapshot, 'bg', $actor);
    $banner = $live->value('banner.banner');
    $snapshotBanner = $snapshotRender->value('banner.banner');
    $api = json_decode(
        json_encode(ContentResponseData::composition($live), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($compiled->schema)->toBeInstanceOf(ContentSchemaData::class)
        ->and($compiled->schema->fields[0])->toBeInstanceOf(ContentFieldDefinitionData::class)
        ->and($compiled->schema->fields[0]->type)->toBe('object')
        ->and($compiled->schema->fields[0]->preset)->toBe('banner')
        ->and($compiled->schema->fields[0]->fields[0]->preset)->toBe('heading')
        ->and($presets->first()?->field)->toBeInstanceOf(ContentFieldDefinitionData::class)
        ->and($compiled->jsonSchema['properties']['banner']['x-content-preset'])->toBe('banner')
        ->and($presets->pluck('alias')->all())
        ->toBe(['banner', 'button', 'heading', 'image', 'link'])
        ->and($presets->firstWhere('alias', 'banner')?->jsonSchema['x-content-preset'])
        ->toBe('banner')
        ->and($compiled->jsonSchema['properties']['banner']['properties']['image']['allOf'][0]['then']['required'])
        ->toBe(['alt'])
        ->and($snapshot->blocks[0])->toBeInstanceOf(ContentCompositionSnapshotBlockData::class)
        ->and($hydratedSnapshot->blocks[0])
        ->toBeInstanceOf(ContentCompositionSnapshotBlockData::class)
        ->and($hydratedSnapshot->blocks[0]->definitionSchema)
        ->toBeInstanceOf(ContentSchemaData::class)
        ->and($live->blocks[0]->fieldTypes['banner'])->toBe('banner')
        ->and($banner)->toBeInstanceOf(RenderedContentBannerData::class)
        ->and($snapshotBanner)->toBeInstanceOf(RenderedContentBannerData::class)
        ->and($banner->heading)->toBeInstanceOf(RenderedContentHeadingData::class)
        ->and($banner->heading->title)->toBe('Помогнете днес')
        ->and($banner->heading->eyebrow)->toBe('Our mission')
        ->and($banner->heading->description)->toBeInstanceOf(RenderedRichTextData::class)
        ->and($banner->heading->level)->toBe(ContentHeadingLevel::H1)
        ->and($banner->image)->toBeInstanceOf(RenderedContentImageData::class)
        ->and($banner->image->media)->toBeInstanceOf(PublicMedia::class)
        ->and($banner->image->alt)->toBe('Volunteers preparing donations')
        ->and($banner->image->caption)->toBeInstanceOf(RenderedRichTextData::class)
        ->and($banner->image->focalX)->toBe(0.4)
        ->and($banner->primaryAction)->toBeInstanceOf(RenderedContentButtonData::class)
        ->and($banner->primaryAction->label)->toBe('Дарете сега')
        ->and($banner->primaryAction->title)->toBe('Donate to our mission')
        ->and($banner->primaryAction->href)->toBe('/donate')
        ->and($banner->primaryAction->rel)->toBe([
            ContentLinkRelationship::NoFollow,
            ContentLinkRelationship::NoOpener,
            ContentLinkRelationship::NoReferrer,
        ])
        ->and($banner->alignment)->toBe(ContentAlignment::Center)
        ->and($snapshotBanner->heading->title)->toBe('Помогнете днес')
        ->and($snapshotBanner->image?->alt)->toBe('Volunteers preparing donations')
        ->and($api['blocks'][0]['values']['banner']['alignment'])->toBe('center')
        ->and($api['blocks'][0]['values']['banner']['primaryAction']['target'])->toBe('_blank')
        ->and($api['blocks'][0]['values']['banner']['primaryAction']['rel'])
        ->toBe(['nofollow', 'noopener', 'noreferrer']);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'homepage-banner',
            key: 'unsafe-banner-link',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'banner' => [
                    'primary_action' => ['href' => 'javascript:alert(1)'],
                ],
            ],
            translations: [
                'en' => [
                    'banner' => [
                        'heading' => ['title' => 'Unsafe'],
                        'primary_action' => ['label' => 'Unsafe'],
                    ],
                ],
            ],
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $missingAlt = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'homepage-banner',
            key: 'missing-image-alt',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'banner' => [
                    'image' => [
                        'media' => $media->id,
                        'decorative' => false,
                    ],
                ],
            ],
            translations: [
                'en' => [
                    'banner' => [
                        'heading' => ['title' => 'Missing image alt'],
                    ],
                ],
            ],
        ),
        $actor,
    );

    expect(fn () => app(PublishContentBlockAction::class)->execute(
        $missingAlt,
        $missingAlt->revision,
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $decorativeImage = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'homepage-banner',
            key: 'decorative-image',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'banner' => [
                    'image' => [
                        'media' => $media->id,
                        'decorative' => true,
                    ],
                ],
            ],
            translations: [
                'en' => [
                    'banner' => [
                        'heading' => ['title' => 'Decorative image'],
                    ],
                ],
            ],
        ),
        $actor,
    );

    expect(app(PublishContentBlockAction::class)->execute(
        $decorativeImage,
        $decorativeImage->revision,
        $actor,
    )->status)->toBe(ContentStatus::Published);

    expect(fn () => app(ContentDefinitionRegistry::class)->register(
        new ContentDefinitionSource(
            key: 'invalid-preset-override',
            name: 'Invalid preset override',
            description: null,
            category: 'testing',
            version: 1,
            view: null,
            schema: [
                'fields' => [[
                    'key' => 'banner',
                    'preset' => 'banner',
                    'type' => 'text',
                ]],
            ],
            allowedScopes: ['site'],
        ),
    ))->toThrow(InvalidArgumentException::class);
});

it('enforces optimistic concurrency and json schema constraints', function (): void {
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'hero',
            scope: 'site',
            scopeKey: 'main-site',
            values: ['layout' => ['columns' => 1]],
            translations: ['en' => ['title' => 'One']],
        ),
        ContentActorData::system(),
    );
    $updated = app(UpdateContentBlockAction::class)->execute(
        $block,
        new UpdateContentBlockData(
            expectedRevision: 1,
            mode: ContentMutationMode::Patch,
            values: ['layout' => ['columns' => 3]],
        ),
        ContentActorData::system(),
    );

    expect($updated->revision)->toBe(2)
        ->and($updated->values['layout']['columns'])->toBe(3);

    expect(fn () => app(UpdateContentBlockAction::class)->execute(
        $updated,
        new UpdateContentBlockData(expectedRevision: 1),
        ContentActorData::system(),
    ))->toThrow(StaleContentException::class);

    expect(fn () => app(UpdateContentBlockAction::class)->execute(
        $updated,
        new UpdateContentBlockData(
            expectedRevision: 2,
            values: ['layout' => ['columns' => 0]],
            translations: ['en' => ['title' => 'Invalid']],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(ContentDefinitionRegistry::class)->register(
        new ContentDefinitionSource(
            key: 'unsafe-json-reference',
            name: 'Unsafe JSON reference',
            description: null,
            category: 'testing',
            version: 1,
            view: null,
            schema: [
                'fields' => [[
                    'key' => 'payload',
                    'type' => 'json',
                    'label' => 'Payload',
                    'settings' => [
                        'schema' => [
                            '$dynamicRef' => 'https://schemas.example.test/content.json',
                        ],
                    ],
                ]],
            ],
        ),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(ContentDefinitionRegistry::class)->register(
        new ContentDefinitionSource(
            key: 'misspelled-setting',
            name: 'Misspelled setting',
            description: null,
            category: 'testing',
            version: 1,
            view: null,
            schema: [
                'fields' => [[
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'settings' => ['max_lenght' => 100],
                ]],
            ],
        ),
    ))->toThrow(InvalidArgumentException::class);
});

it('enforces private media uploader ownership', function (): void {
    $owned = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
        'mime_type' => 'image/jpeg',
    ]);
    $actor = new ContentActorData('member', '42');
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'private',
            scope: 'site',
            scopeKey: 'main-site',
            visibility: ContentVisibility::Private,
            values: ['image' => $owned->id],
        ),
        $actor,
    );

    expect(MediaAssociation::query()->where('associable_id', $block->id)->exists())->toBeTrue();

    $foreign = Media::factory()->create([
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '84',
        'mime_type' => 'image/jpeg',
    ]);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'foreign',
            scope: 'site',
            scopeKey: 'main-site',
            visibility: ContentVisibility::Private,
            values: ['image' => $foreign->id],
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class);
});

it('keeps routes disabled by default and reports a healthy installation', function (): void {
    expect(config('content.routes.management.enabled'))->toBeFalse()
        ->and(config('content.routes.public.enabled'))->toBeFalse();

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"healthy": true');
});

it('lists reads archives and deletes blocks through revision-safe actions', function (): void {
    Event::fake([ContentBlockChanged::class]);
    $media = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'lifecycle',
            scope: 'site',
            scopeKey: 'main-site',
            values: ['image' => $media->id],
        ),
        $actor,
    );
    $read = app(GetContentBlockAction::class)->execute($block->id, $actor);
    $page = app(ListContentBlocksAction::class)->execute(FilterSet::none(), $actor, 10);

    expect($read->id)->toBe($block->id)
        ->and($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($block->id);

    $archived = app(ArchiveContentBlockAction::class)->execute(
        $block,
        $block->revision,
        $actor,
    );

    expect($archived->status)->toBe(ContentStatus::Archived)
        ->and($archived->revision)->toBe(2);

    expect(fn () => app(DeleteContentBlockAction::class)->execute(
        $archived,
        1,
        $actor,
    ))->toThrow(StaleContentException::class);

    app(DeleteContentBlockAction::class)->execute(
        $archived,
        $archived->revision,
        $actor,
    );

    expect(ContentBlock::withTrashed()->findOrFail($block->id)->trashed())->toBeTrue()
        ->and(MediaAssociation::query()->where('associable_id', $block->id)->exists())->toBeFalse()
        ->and(ContentRevision::query()->where('content_block_id', $block->id)->count())->toBe(3);
    Event::assertDispatched(ContentBlockChanged::class, 3);

    $deleted = ContentBlock::withTrashed()->findOrFail($block->id);
    $restored = app(RestoreContentBlockAction::class)->execute(
        $deleted->id,
        $deleted->revision,
        $actor,
    );

    expect($restored->trashed())->toBeFalse()
        ->and($restored->status)->toBe(ContentStatus::Draft)
        ->and($restored->revision)->toBe(4)
        ->and(MediaAssociation::query()->where('associable_id', $block->id)->exists())->toBeTrue()
        ->and(ContentRevision::query()->where('content_block_id', $block->id)->count())->toBe(4);
    Event::assertDispatched(ContentBlockChanged::class, 4);
});

it('updates placement trees and rejects stale revisions and cycles', function (): void {
    Event::fake([ContentPlacementChanged::class]);
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'tree',
            scope: 'site',
            scopeKey: 'main-site',
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Tree page']);
    $parent = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'parent',
        ),
        $actor,
    );
    $child = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'child',
            parentId: $parent->id,
        ),
        $actor,
    );
    expect(fn () => app(UpdateContentPlacementAction::class)->execute(
        $child,
        new UpdateContentPlacementData(
            expectedRevision: $child->revision,
            region: 'sidebar',
            parentId: $parent->id,
            sortOrder: 20,
            isVisible: false,
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $updated = app(UpdateContentPlacementAction::class)->execute(
        $child,
        new UpdateContentPlacementData(
            expectedRevision: $child->revision,
            region: 'main',
            parentId: $parent->id,
            sortOrder: 20,
            isVisible: false,
            overrides: ['enabled' => false],
        ),
        $actor,
    );

    expect($updated->revision)->toBe(2)
        ->and($updated->region)->toBe('main')
        ->and($updated->sort_order)->toBe(20)
        ->and($updated->is_visible)->toBeFalse()
        ->and($updated->overrides)->toBe(['enabled' => false]);

    expect(fn () => app(UpdateContentPlacementAction::class)->execute(
        $updated,
        new UpdateContentPlacementData(
            expectedRevision: 1,
            region: 'main',
            parentId: $parent->id,
            sortOrder: 0,
            isVisible: true,
        ),
        $actor,
    ))->toThrow(StaleContentException::class);

    expect(fn () => app(UpdateContentPlacementAction::class)->execute(
        $parent,
        new UpdateContentPlacementData(
            expectedRevision: $parent->revision,
            region: 'main',
            parentId: $child->id,
            sortOrder: 0,
            isVisible: true,
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class);
    Event::assertDispatched(ContentPlacementChanged::class, 3);
});

it('loads deterministic definition files and guards custom view destinations', function (): void {
    $temporary = sys_get_temp_dir().'/nvl-content-'.Str::uuid();
    $definitionRoot = $temporary.'/definitions';
    $viewRoot = $temporary.'/views';
    $outsideViewRoot = $temporary.'/outside-views';
    File::ensureDirectoryExists($definitionRoot);
    File::ensureDirectoryExists($viewRoot);
    File::ensureDirectoryExists($outsideViewRoot);

    try {
        File::put(
            $definitionRoot.'/z.content.json',
            json_encode([
                'z-block' => [
                    'name' => 'Z block',
                    'schema' => ['fields' => []],
                ],
            ], JSON_THROW_ON_ERROR),
        );
        File::put(
            $definitionRoot.'/a.content.json',
            json_encode([
                'a-block' => [
                    'name' => 'A block',
                    'schema' => ['fields' => []],
                ],
            ], JSON_THROW_ON_ERROR),
        );
        config()->set([
            'content.definitions' => [],
            'content.definition_paths' => [$definitionRoot],
            'content.allowed_definition_roots' => [$temporary],
            'content.view_publishing.allowed_roots' => [$viewRoot],
        ]);
        $definitions = app(ContentDefinitionLoader::class)->load();

        expect(array_map(
            static fn ($definition): string => $definition->key,
            $definitions,
        ))->toBe(['a-block', 'z-block']);

        $destination = $viewRoot.'/starter';
        $this->artisan('nvl:content:views:publish', ['--path' => $destination])
            ->assertSuccessful();
        expect(File::exists($destination.'/components/composition.blade.php'))->toBeTrue();
        $this->artisan('nvl:content:views:publish', ['--path' => $destination])
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped existing');
        $this->artisan('nvl:content:views:publish', [
            '--path' => $destination,
            '--force' => true,
        ])->assertSuccessful();

        expect(fn () => $this->artisan('nvl:content:views:publish', [
            '--path' => '',
        ])->run())->toThrow(InvalidArgumentException::class);

        $fileDestination = $viewRoot.'/not-a-directory';
        File::put($fileDestination, 'file');
        expect(fn () => $this->artisan('nvl:content:views:publish', [
            '--path' => $fileDestination,
        ])->run())->toThrow(InvalidArgumentException::class);

        expect(fn () => $this->artisan('nvl:content:views:publish', [
            '--path' => $temporary.'/outside',
        ]))->toThrow(InvalidArgumentException::class);

        config()->set('content.view_publishing.allowed_roots', []);
        expect(fn () => $this->artisan('nvl:content:views:publish', [
            '--path' => $destination,
        ])->run())->toThrow(InvalidArgumentException::class);
        config()->set('content.view_publishing.allowed_roots', [$temporary.'/missing-root']);
        expect(fn () => $this->artisan('nvl:content:views:publish', [
            '--path' => $temporary.'/missing-root/views',
        ])->run())->toThrow(InvalidArgumentException::class);
        config()->set('content.view_publishing.allowed_roots', [$viewRoot]);

        $symlink = $viewRoot.'/linked-outside';
        symlink($outsideViewRoot, $symlink);
        expect(fn () => $this->artisan('nvl:content:views:publish', [
            '--path' => $symlink,
            '--force' => true,
        ]))->toThrow(InvalidArgumentException::class);
        unlink($symlink);
    } finally {
        if (isset($symlink) && is_link($symlink)) {
            unlink($symlink);
        }

        File::deleteDirectory($temporary);
    }
});

it('exposes independently configurable authorized management and public APIs', function (): void {
    config()->set([
        'content.routes.management.enabled' => true,
        'content.routes.management.prefix' => 'api/internal/content-manager',
        'content.routes.management.name' => 'consumer.content.management',
        'content.routes.management.middleware' => [],
        'content.routes.public.enabled' => true,
        'content.routes.public.prefix' => 'api/site-content',
        'content.routes.public.name' => 'consumer.content.public',
        'content.routes.public.middleware' => [],
    ]);
    require __DIR__.'/../../routes/api.php';
    app('router')->getRoutes()->refreshNameLookups();

    expect(Route::has('consumer.content.management.blocks.store'))->toBeTrue()
        ->and(Route::has('consumer.content.management.presets.index'))->toBeTrue()
        ->and(Route::has('consumer.content.management.definitions.index'))->toBeTrue()
        ->and(Route::has('consumer.content.management.placements.index'))->toBeTrue()
        ->and(Route::has('consumer.content.management.editor.show'))->toBeTrue()
        ->and(Route::has('consumer.content.management.compositions.preview'))->toBeTrue()
        ->and(Route::has('consumer.content.management.placements.destroy'))->toBeTrue()
        ->and(Route::has('consumer.content.management.blocks.restore'))->toBeTrue()
        ->and(Route::has('consumer.content.public.compositions.show'))->toBeTrue();

    $this->getJson('/api/internal/content-manager/presets')
        ->assertOk()
        ->assertJsonPath('data.0.alias', 'banner')
        ->assertJsonPath('data.0.field.preset', 'banner')
        ->assertJsonPath('data.0.jsonSchema.x-content-preset', 'banner');
    $this->getJson('/api/internal/content-manager/definitions')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'hero')
        ->assertJsonPath(
            'data.0.jsonSchema.$schema',
            'https://json-schema.org/draft/2020-12/schema',
        );
    $this->postJson('/api/internal/content-manager/blocks', [
        'definition' => 'hero',
        'key' => 'invalid-object-shape',
        'scope' => 'site',
        'scopeKey' => 'main-site',
        'values' => ['not-an-object'],
    ])->assertUnprocessable();

    $created = $this->postJson('/api/internal/content-manager/blocks', [
        'definition' => 'hero',
        'key' => 'api-hero',
        'scope' => 'site',
        'scopeKey' => 'main-site',
        'translations' => [
            'en' => ['title' => 'API title'],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.definition', 'hero')
        ->assertJsonPath('data.revision', 1);
    $blockId = $created->json('data.id');
    expect($blockId)->toBeString();
    expect(ContentBlock::query()->find($blockId))->toBeInstanceOf(ContentBlock::class);

    $this->getJson('/api/internal/content-manager/blocks?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.id', $blockId)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1);
    $this->getJson("/api/internal/content-manager/blocks/{$blockId}")
        ->assertOk()
        ->assertJsonPath('data.id', $blockId)
        ->assertJsonPath('data.revision', 1);
    $this->putJson("/api/internal/content-manager/blocks/{$blockId}", [
        'expectedRevision' => 1,
        'metadata' => ['source' => 'management-api'],
    ])
        ->assertOk()
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.metadata.source', 'management-api');
    $this->postJson("/api/internal/content-manager/blocks/{$blockId}/publish", [
        'expectedRevision' => 2,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.revision', 3);
    $this->putJson("/api/internal/content-manager/blocks/{$blockId}", [
        'expectedRevision' => 1,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'stale_content')
        ->assertJsonPath('error.context.expected_revision', 1)
        ->assertJsonPath('error.context.actual_revision', 3);
    $owner = TestContentOwner::query()->create(['name' => 'API page']);

    $placement = $this->postJson(
        "/api/internal/content-manager/owners/page/{$owner->id}/groups/main/blocks/{$blockId}/placements",
        [
            'key' => 'hero',
            'region' => 'main',
        ],
    )
        ->assertCreated()
        ->assertJsonPath('data.ownerType', 'page')
        ->assertJsonPath('data.ownerId', $owner->id)
        ->assertJsonPath('data.group', 'main');
    $placementId = $placement->json('data.id');

    $this->getJson("/api/internal/content-manager/owners/page/{$owner->id}/groups")
        ->assertOk()
        ->assertJsonPath(
            'data',
            ['default', 'homepage', 'main', 'primary', 'secondary'],
        );
    $this->getJson("/api/internal/content-manager/owners/page/{$owner->id}/groups/main/placements")
        ->assertOk()
        ->assertJsonPath('data.0.id', $placementId)
        ->assertJsonPath('data.0.revision', 1);
    $this->getJson("/api/internal/content-manager/owners/page/{$owner->id}/groups/main/editor")
        ->assertOk()
        ->assertJsonPath('data.ownerType', 'page')
        ->assertJsonPath('data.ownerId', $owner->id)
        ->assertJsonPath('data.group', 'main')
        ->assertJsonPath('data.placementLimit', 1_000)
        ->assertJsonPath('data.definitions.0.key', 'hero')
        ->assertJsonPath('data.presets.0.alias', 'banner')
        ->assertJsonPath('data.placements.0.id', $placementId)
        ->assertJsonPath('data.placements.0.block.definition', 'hero')
        ->assertJsonPath('data.placements.0.block.translations.en.title', 'API title');
    $this->getJson("/api/internal/content-manager/owners/page/{$owner->id}/groups/main/preview?locale=en")
        ->assertOk()
        ->assertJsonPath('data.blocks.0.key', 'hero');

    $this->getJson("/api/site-content/owners/page/{$owner->id}/groups/main/composition?locale=en")
        ->assertOk()
        ->assertJsonPath('data.group', 'main')
        ->assertJsonPath('data.blocks.0.key', 'hero')
        ->assertJsonPath('data.blocks.0.values.title', 'API title');

    expect($placementId)->toBeString();
    $this->putJson("/api/internal/content-manager/placements/{$placementId}", [
        'expectedRevision' => 1,
        'region' => 'main',
        'parentId' => null,
        'sortOrder' => 10,
        'isVisible' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.sortOrder', 10)
        ->assertJsonPath('data.isVisible', false);
    $this->deleteJson("/api/internal/content-manager/placements/{$placementId}", [
        'expectedRevision' => 2,
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
    $this->postJson("/api/internal/content-manager/blocks/{$blockId}/archive", [
        'expectedRevision' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.revision', 4);
    $this->deleteJson("/api/internal/content-manager/blocks/{$blockId}", [
        'expectedRevision' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
    $this->postJson("/api/internal/content-manager/blocks/{$blockId}/restore", [
        'expectedRevision' => 5,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.revision', 6);
});

it('keeps patch updates non-destructive by default and resolves nested block values', function (): void {
    $actor = new ContentActorData('member', (string) Str::uuid());
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'safe-default-patch',
            scope: 'site',
            scopeKey: 'main-site',
            values: ['layout' => ['columns' => 2], 'enabled' => true],
            translations: ['en' => ['title' => 'Nested title']],
            metadata: ['editor' => 'content-team'],
        ),
        $actor,
    );
    $updated = app(UpdateContentBlockAction::class)->execute(
        $block,
        new UpdateContentBlockData(expectedRevision: $block->revision),
        $actor,
    );

    expect($updated->values)->toMatchArray([
        'layout' => ['columns' => 2],
        'enabled' => true,
    ])
        ->and($updated->metadata)->toBe(['editor' => 'content-team'])
        ->and($updated->translations)->toHaveCount(1)
        ->and($updated->created_by_id)->toBe($actor->id)
        ->and($updated->updated_by_id)->toBe($actor->id);

    $published = app(PublishContentBlockAction::class)->execute(
        $updated,
        $updated->revision,
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Nested page']);
    $parent = app(PlaceContentBlockAction::class)->execute(
        $published,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'parent',
        ),
        $actor,
    );
    app(PlaceContentBlockAction::class)->execute(
        $published,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'child',
            parentId: $parent->id,
        ),
        $actor,
    );
    $composition = app(ContentRenderer::class)->render(
        $owner,
        'default',
        'en',
        $actor,
    );

    expect($composition->value('child.title'))->toBe('Nested title')
        ->and($composition->firstValue('title'))->toBe('Nested title');
});

it('prunes hidden and non-public placement subtrees without promoting children', function (): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'subtree',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Secret child']],
        ),
        $actor,
    );
    $block = app(PublishContentBlockAction::class)->execute(
        $block,
        $block->revision,
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Subtree page']);
    $parent = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'hidden-parent',
        ),
        $actor,
    );
    app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'visible-child',
            parentId: $parent->id,
        ),
        $actor,
    );
    app(UpdateContentPlacementAction::class)->execute(
        $parent,
        new UpdateContentPlacementData(
            expectedRevision: $parent->revision,
            region: 'main',
            parentId: null,
            sortOrder: 0,
            isVisible: false,
        ),
        $actor,
    );

    $live = app(ContentRenderer::class)->render(
        $owner,
        'default',
        'en',
        $actor,
    );
    $snapshot = app(ContentSnapshotService::class)->capture(
        $owner,
        'default',
        $actor,
    );

    expect($live->blocks)->toBe([])
        ->and($live->regions)->toBe([])
        ->and($snapshot->blocks)->toBe([]);
});

it('binds snapshots to owners and rejects missing parents and cycles', function (): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'snapshot-integrity',
            scope: 'site',
            scopeKey: 'main-site',
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Snapshot owner']);
    $otherOwner = TestContentOwner::query()->create(['name' => 'Other owner']);
    app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'snapshot-block',
        ),
        $actor,
    );
    $snapshot = app(ContentSnapshotService::class)->capture(
        $owner,
        'default',
        $actor,
    );
    $ownerTampered = new ContentCompositionSnapshotData(
        ownerType: 'page',
        ownerId: $otherOwner->id,
        group: 'default',
        blocks: $snapshot->blocks,
        version: $snapshot->version,
    );

    expect(fn () => app(ContentSnapshotService::class)->render(
        $ownerTampered,
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $records = $snapshot->blocks;
    $records[0] = ContentCompositionSnapshotBlockData::from([
        ...$records[0]->toArray(),
        'parent_id' => $records[0]->placementId,
    ]);
    $version = app(CanonicalJson::class)->hash([
        'owner_type' => 'page',
        'owner_id' => $owner->id,
        'group' => 'default',
        'blocks' => array_map(
            static fn (ContentCompositionSnapshotBlockData $record): array => $record->toArray(),
            $records,
        ),
    ]);
    $cyclic = new ContentCompositionSnapshotData(
        ownerType: 'page',
        ownerId: $owner->id,
        group: 'default',
        blocks: $records,
        version: $version,
    );

    expect(fn () => app(ContentSnapshotService::class)->render(
        $cyclic,
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $snapshotFor = static function (array $records) use ($owner): ContentCompositionSnapshotData {
        return new ContentCompositionSnapshotData(
            ownerType: 'page',
            ownerId: $owner->id,
            group: 'default',
            blocks: $records,
            version: app(CanonicalJson::class)->hash([
                'owner_type' => 'page',
                'owner_id' => $owner->id,
                'group' => 'default',
                'blocks' => array_map(
                    static fn (ContentCompositionSnapshotBlockData $record): array => $record->toArray(),
                    $records,
                ),
            ]),
        );
    };
    $root = $snapshot->blocks[0];
    $missingParent = ContentCompositionSnapshotBlockData::from([
        ...$root->toArray(),
        'parent_id' => (string) Str::uuid(),
    ]);

    expect(fn () => app(ContentSnapshotService::class)->render(
        $snapshotFor([$root, $root]),
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ContentSnapshotService::class)->render(
        $snapshotFor([$missingParent]),
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $child = ContentCompositionSnapshotBlockData::from([
        ...$root->toArray(),
        'placement_id' => (string) Str::uuid(),
        'parent_id' => $root->placementId,
    ]);
    $differentRegionChild = ContentCompositionSnapshotBlockData::from([
        ...$child->toArray(),
        'region' => 'sidebar',
    ]);

    expect(fn () => app(ContentSnapshotService::class)->render(
        $snapshotFor([$root, $differentRegionChild]),
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    $nested = app(ContentSnapshotService::class)->render(
        $snapshotFor([$root, $child]),
        'en',
        $actor,
    );

    expect($nested->blocks[0]->children)->toHaveCount(1);

    config()->set('content.placements.maximum_per_group', 1);
    expect(fn () => app(ContentSnapshotService::class)->render(
        $snapshotFor([$root, $child]),
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);
    config()->set('content.placements.maximum_per_group', 1_000);

    $withoutView = ContentCompositionSnapshotBlockData::from([
        ...$root->toArray(),
        'definition_view' => null,
    ]);
    config()->set('content.rendering.default_view', '');
    expect(fn () => app(ContentSnapshotService::class)->render(
        $snapshotFor([$withoutView]),
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);
    config()->set('content.rendering.default_view', 'missing::content-view');
    expect(fn () => app(ContentSnapshotService::class)->render(
        $snapshotFor([$withoutView]),
        'en',
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    app(UpdateContentBlockAction::class)->execute(
        $block,
        new UpdateContentBlockData(
            expectedRevision: $block->revision,
            translations: ['en' => ['title' => 'Snapshot publish validation']],
        ),
        $actor,
    );
    config()->set('content.rendering.default_view', 'nvl-content::blocks.default');

    expect(app(ContentSnapshotService::class)->capture(
        $owner,
        'default',
        $actor,
        publishing: true,
    )->blocks)->toHaveCount(1);
});

it('lists definitions and safely unplaces leaf nodes before deleting blocks', function (): void {
    Event::fake([ContentPlacementChanged::class]);
    $actor = ContentActorData::system();
    $definitions = app(ListContentDefinitionsAction::class)->execute($actor);
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'unplace',
            scope: 'site',
            scopeKey: 'main-site',
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Unplace page']);
    $parent = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'parent',
        ),
        $actor,
    );
    $child = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'child',
            parentId: $parent->id,
        ),
        $actor,
    );

    expect($definitions->pluck('key')->all())->toContain('hero');
    expect(fn () => app(DeleteContentBlockAction::class)->execute(
        $block,
        $block->revision,
        $actor,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DeleteContentPlacementAction::class)->execute(
        $parent,
        $parent->revision,
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    app(DeleteContentPlacementAction::class)->execute($child, $child->revision, $actor);
    app(DeleteContentPlacementAction::class)->execute($parent, $parent->revision, $actor);
    app(DeleteContentBlockAction::class)->execute($block, $block->revision, $actor);

    expect(ContentPlacement::query()->count())->toBe(0)
        ->and(ContentBlock::withTrashed()->findOrFail($block->id)->trashed())->toBeTrue();
    Event::assertDispatched(ContentPlacementChanged::class, 4);
    Event::assertDispatched(
        ContentPlacementChanged::class,
        static fn (ContentPlacementChanged $event): bool => $event->placementId === $child->id
            && $event->event === ContentPlacementEvent::Deleted
            && $event->ownerType === 'page'
            && $event->ownerId === $owner->id
            && $event->group === 'default'
            && $event->blockId === $block->id,
    );
});

it('preserves localized media association identity and returns typed private projections', function (): void {
    $definition = new ContentDefinitionSource(
        key: 'localized-media',
        name: 'Localized media',
        description: null,
        category: 'media',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'asset',
                'type' => 'media',
                'label' => 'Asset',
                'localized' => true,
                'settings' => ['mime_types' => ['image/jpeg']],
            ]],
        ],
        allowedScopes: ['site'],
    );
    app(ContentDefinitionRegistry::class)->register($definition);
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
    $public = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
    $localized = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'localized-media',
            key: 'localized-media',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['bg' => ['asset' => $public->id]],
        ),
        ContentActorData::system(),
    );

    expect(MediaAssociation::query()
        ->where('associable_id', $localized->id)
        ->value('locale'))->toBe('bg');

    $actor = new ContentActorData('member', '42');
    $private = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => '42',
    ]);
    $privateBlock = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'private-projection',
            scope: 'site',
            scopeKey: 'main-site',
            visibility: ContentVisibility::Private,
            values: ['image' => $private->id],
            translations: ['en' => ['title' => 'Private']],
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Private page']);
    app(PlaceContentBlockAction::class)->execute(
        $privateBlock,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'private',
        ),
        $actor,
    );
    $composition = app(ContentRenderer::class)->render(
        $owner,
        'default',
        'en',
        $actor,
        publicOnly: false,
    );

    expect($composition->value('private.image'))
        ->toBeInstanceOf(RenderedPrivateMediaData::class);
});

it('serves a rendered private media URL when a system actor is not the uploader', function (): void {
    Route::get('/media/private/{owner}/{media}', [MediaAssetController::class, 'showPrivate'])
        ->middleware(['signed', SubstituteBindings::class])
        ->name('media.private.show');

    $private = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'uploaded_by_type' => 'member',
        'uploaded_by' => 'uploader-42',
    ]);
    Storage::disk('public')->put(
        app(MediaPathResolver::class)->mediaPath($private),
        'private image',
    );
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'private-system-projection',
            scope: 'site',
            scopeKey: 'main-site',
            visibility: ContentVisibility::Private,
            values: ['image' => $private->id],
            translations: ['en' => ['title' => 'Private']],
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Private system page']);
    app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(key: 'private-system'),
        $actor,
    );
    $composition = app(ContentRenderer::class)->render(
        $owner,
        'default',
        'en',
        $actor,
        publicOnly: false,
    );
    $rendered = $composition->value('private-system.image');

    expect($rendered)
        ->toBeInstanceOf(RenderedPrivateMediaData::class)
        ->and(parse_url($rendered->url, PHP_URL_PATH))
        ->toBe("/media/private/uploader-42/{$private->id}");

    $this->get($rendered->url)
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('fails closed for invalid route middleware unsafe URLs and unbounded definition discovery', function (): void {
    config()->set('content.routes.management.middleware', ['api', null]);
    expect(fn () => ContentRouteConfiguration::middleware('management'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'unsafe-url',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'links' => [['label' => 'Unsafe', 'url' => 'http://example.com']],
            ],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);

    $references = new ContentReferenceRegistry(
        app(),
        app(ContentPayloadGuard::class),
    );
    $references->register('unsafe', UnsafeReferenceResolver::class);
    expect(fn () => $references->display(
        'unsafe',
        'resource-1',
        new ContentValidationContext(
            ContentActorData::system(),
            'en',
            'unsafe',
            ContentVisibility::Private,
        ),
    ))->toThrow(InvalidArgumentException::class);

    $temporary = sys_get_temp_dir().'/nvl-content-limit-'.Str::uuid();
    File::ensureDirectoryExists($temporary);

    try {
        File::put($temporary.'/a.content.json', '{"a":{"name":"A","schema":{"fields":[]}}}');
        File::put($temporary.'/b.content.json', '{"b":{"name":"B","schema":{"fields":[]}}}');
        config()->set([
            'content.definition_paths' => [$temporary],
            'content.allowed_definition_roots' => [$temporary],
            'content.definition_limits.maximum_files' => 1,
        ]);

        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definition_paths' => [],
            'content.required_definition_paths' => [$temporary.'/missing'],
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);
    } finally {
        File::deleteDirectory($temporary);
    }
});
