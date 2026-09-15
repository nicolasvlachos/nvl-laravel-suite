<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Contracts\ContentReferenceResolver;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Models\ContentRevision;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Services\ContentRenderer;
use Nvl\Content\Services\ContentSnapshotService;
use Nvl\Content\Tests\Fixtures\PublishingTextFieldAdapter;
use Nvl\Content\Tests\Fixtures\TestContentOwner;
use Nvl\Content\Tests\Fixtures\TestReferenceResolver;
use Nvl\Content\Validation\ContentValidationContext;

beforeEach(function (): void {
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'required-title',
        name: 'Required title',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: ['fields' => [[
            'key' => 'title',
            'type' => 'text',
            'label' => 'Title',
            'required' => true,
        ]]],
        allowedScopes: ['site'],
    ));
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

it('requires publishing an existing placement to validate its final required values', function (): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'required-title',
        key: 'publication-title',
        scope: 'site',
        scopeKey: 'main-site',
        values: ['title' => 'Base title'],
    ), $actor);
    $owner = TestContentOwner::query()->create(['name' => 'page']);
    $placement = app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'placement',
        overrides: ['title' => null],
    ), $actor);

    expect($placement->overrides)->toBe(['title' => null]);
    expect(fn () => app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor))
        ->toThrow(InvalidArgumentException::class);

    expect($block->refresh()->status)->toBe(ContentStatus::Draft)
        ->and($block->revision)->toBe(1)
        ->and(ContentRevision::query()->where('content_block_id', $block->id)->count())->toBe(1)
        ->and($placement->refresh()->revision)->toBe(1);
});

it('requires publishing snapshots to validate final placement overrides', function (): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'required-title',
        key: 'publication-title',
        scope: 'site',
        scopeKey: 'main-site',
        values: ['title' => 'Base title'],
    ), $actor);
    $owner = TestContentOwner::query()->create(['name' => 'page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'placement',
        overrides: ['title' => null],
    ), $actor);

    expect(fn () => app(ContentSnapshotService::class)->capture($owner, 'default', $actor, publishing: true))
        ->toThrow(InvalidArgumentException::class);
});

it('permits publishing a snapshot when the override supplies the required field', function (): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'required-title',
        key: 'publication-title',
        scope: 'site',
        scopeKey: 'main-site',
    ), $actor);
    $owner = TestContentOwner::query()->create(['name' => 'page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'placement',
        overrides: ['title' => 'Placement title'],
    ), $actor);

    $snapshots = app(ContentSnapshotService::class);
    $snapshot = $snapshots->capture($owner, 'default', $actor, publishing: true);

    expect($snapshots->render($snapshot, 'en', $actor)->blocks[0]->values['title'])->toBe('Placement title');
});

it('allows partial optional locales when all required publication locales are complete', function (): void {
    $actor = ContentActorData::system();
    config()->set('content.locales.required_on_publish', ['en']);
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'hero',
        key: 'partial-optional-locale',
        scope: 'site',
        scopeKey: 'main-site',
        translations: [
            'en' => ['title' => 'Complete required locale'],
            'bg' => ['body' => '<p>Partial optional locale</p>'],
        ],
    ), $actor);

    $published = app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor);
    $owner = TestContentOwner::query()->create(['name' => 'Localized page']);
    app(PlaceContentBlockAction::class)->execute($published, $owner, 'default', new PlaceContentBlockData(
        key: 'localized',
    ), $actor);
    config()->set('translatable.fallback_locales', ['en']);
    $rendered = app(ContentRenderer::class)->render($owner, 'default', 'bg', $actor);

    expect($published->status)->toBe(ContentStatus::Published)
        ->and($rendered->blocks[0]->values['title'])->toBe('Complete required locale');
});

it('still requires every configured publication locale to be complete', function (array $requiredLocales): void {
    config()->set('content.locales.required_on_publish', $requiredLocales);
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'hero',
        key: 'missing-mandatory-title',
        scope: 'site',
        scopeKey: 'main-site',
        translations: ['en' => ['body' => '<p>Missing title</p>'], 'bg' => ['title' => 'Заглавие']],
    ), $actor);

    expect(fn () => app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor))
        ->toThrow(InvalidArgumentException::class, 'Required localized field [title] is missing for [en]');
})->with([
    'explicit required locale' => [['en']],
    'all locales by default' => [[]],
]);

it('continues validating every supplied optional locale value', function (): void {
    config()->set('content.locales.required_on_publish', ['en']);

    expect(fn () => app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'hero',
        key: 'invalid-optional-value',
        scope: 'site',
        scopeKey: 'main-site',
        translations: ['en' => ['title' => 'Valid'], 'bg' => ['body' => 123]],
    ), ContentActorData::system()))->toThrow(InvalidArgumentException::class, 'must be HTML');
});

it('validates nested mandatory copy while allowing partial optional locale objects', function (): void {
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'localized-object',
        name: 'Localized object',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: ['fields' => [[
            'key' => 'copy',
            'type' => 'object',
            'label' => 'Copy',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'localized' => true, 'required' => true],
                ['key' => 'body', 'type' => 'text', 'label' => 'Body', 'localized' => true],
            ],
        ]]],
        allowedScopes: ['site'],
    ));
    $actor = ContentActorData::system();
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'localized-object',
        key: 'nested-partial-locale',
        scope: 'site',
        scopeKey: 'main-site',
        values: ['copy' => []],
        translations: ['en' => ['copy' => ['title' => 'Title']], 'bg' => ['copy' => ['body' => 'Текст']]],
    ), $actor);

    expect(app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor)->status)
        ->toBe(ContentStatus::Published);
});

it('revalidates publication overrides with their owner group and transaction context', function (bool $snapshot): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'hero',
        key: 'publication-context',
        scope: 'site',
        scopeKey: 'main-site',
        translations: ['en' => ['title' => 'Context']],
    ), $actor);
    $owner = TestContentOwner::query()->create(['name' => 'Context page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'homepage', new PlaceContentBlockData(
        key: 'context',
        overrides: ['article' => 'article-1'],
    ), $actor);
    $level = DB::connection()->transactionLevel();
    $resolver = Mockery::mock(ContentReferenceResolver::class);
    $resolver->shouldReceive('alias')->andReturn('article');
    $resolver->shouldReceive('exists')->once()->andReturnUsing(
        function (string $identifier, ContentValidationContext $context) use ($actor, $owner, $level): bool {
            expect($identifier)->toBe('article-1')
                ->and($context->owner?->getKey())->toBe($owner->id)
                ->and($context->group)->toBe('homepage')
                ->and($context->actor)->toBe($actor)
                ->and($context->publishing)->toBeTrue()
                ->and(DB::connection()->transactionLevel())->toBeGreaterThan($level);

            return true;
        },
    );
    app()->instance(TestReferenceResolver::class, $resolver);

    if ($snapshot) {
        app(ContentSnapshotService::class)->capture($owner, 'homepage', $actor, publishing: true);
    } else {
        app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor);
    }
})->with(['live publication' => false, 'snapshot publication' => true]);

it('freezes the normalized final publishing payload without reapplying stale overrides', function (): void {
    app(ContentFieldTypeRegistry::class)->register(new PublishingTextFieldAdapter);
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'publishing-text', name: 'Publishing text', description: null, category: 'testing', version: 1, view: null,
        schema: ['fields' => [['key' => 'text', 'type' => 'publishing_text', 'label' => 'Text']]],
        allowedScopes: ['site'],
    ));
    $actor = ContentActorData::system();
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'publishing-text', key: 'published-normalization', scope: 'site', scopeKey: 'main-site',
        values: ['text' => 'Base value'],
    ), $actor);
    $owner = TestContentOwner::query()->create(['name' => 'Publishing normalization page']);
    app(PlaceContentBlockAction::class)->execute($block, $owner, 'default', new PlaceContentBlockData(
        key: 'text', overrides: ['text' => 'Placed value'],
    ), $actor);
    $snapshots = app(ContentSnapshotService::class);
    $snapshot = $snapshots->capture($owner, 'default', $actor, publishing: true);

    expect($snapshot->blocks[0]->values)->toBe(['text' => 'Published: Placed value'])
        ->and($snapshot->blocks[0]->overrides)->toBe([])
        ->and($snapshots->render($snapshot, 'en', $actor)->blocks[0]->values['text'])->toBe('Published: Placed value')
        ->and($block->refresh()->values)->toBe(['text' => 'Base value']);
});
