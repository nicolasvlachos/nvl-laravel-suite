<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\GetContentBlockAction;
use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Content\Actions\ListContentBlocksAction;
use Nvl\Content\Actions\ListContentPlacementsAction;
use Nvl\Content\Actions\ListOwnerContentPlacementSummariesAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Actions\UpdateContentBlockAction;
use Nvl\Content\Actions\UpdateContentPlacementAction;
use Nvl\Content\Content as ContentEngine;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentBlockQueryScope;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Data\ContentScopeData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentPlacementData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Exceptions\ContentScopeOverflowException;
use Nvl\Content\Facades\Content;
use Nvl\Content\FieldTypes\ReferenceFieldTypeAdapter;
use Nvl\Content\FieldTypes\RichTextFieldTypeAdapter;
use Nvl\Content\FieldTypes\StringFieldTypeAdapter;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Services\ContentDefinitionLoader;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentLocalizedValues;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentPayloadGuard;
use Nvl\Content\Services\ContentReferenceRegistry;
use Nvl\Content\Services\ContentRenderResources;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Tests\Fixtures\TestContentOwner;
use Nvl\Content\Tests\Fixtures\TestIntegerContentOwner;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Content\Validation\ContentValueValidator;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Enums\FilterOperator;

beforeEach(function (): void {
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

it('preserves the original placement event constructor contract', function (): void {
    $event = new ContentPlacementChanged(
        'placement-1',
        ContentPlacementEvent::Deleted,
        2,
        ContentActorData::system(),
    );

    expect($event->ownerType)->toBeNull()
        ->and($event->ownerId)->toBeNull()
        ->and($event->group)->toBeNull()
        ->and($event->blockId)->toBeNull();
});

it('rebuilds request-aware Content services between container scopes', function (): void {
    $content = app(ContentEngine::class);
    $localizedValues = app(ContentLocalizedValues::class);

    app()->forgetScopedInstances();

    expect(app(ContentEngine::class))->not->toBe($content)
        ->and(app(ContentLocalizedValues::class))->not->toBe($localizedValues);
});

it('preserves the Content service constructor and appends its optional editor dependency', function (): void {
    $parameters = (new ReflectionClass(ContentEngine::class))
        ->getConstructor()?->getParameters() ?? [];

    expect(array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        array_slice($parameters, 0, 22),
    ))->toBe([
        'listDefinitions',
        'listPresets',
        'listBlocks',
        'getBlock',
        'syncDefinitions',
        'planDefinitionMigrations',
        'applyDefinitionMigrations',
        'listGroups',
        'listPlacements',
        'createBlock',
        'updateBlock',
        'publishBlock',
        'archiveBlock',
        'deleteBlock',
        'restoreBlock',
        'placeBlock',
        'updatePlacement',
        'deletePlacement',
        'owners',
        'renderer',
        'snapshots',
        'resolveScopes',
    ])->and(array_map(
        static fn (ReflectionParameter $parameter): bool => $parameter->isOptional(),
        array_slice($parameters, 22),
    ))->toBe([true]);
});

it('resolves the editor for a Content service built with the legacy constructor', function (): void {
    $reflection = new ReflectionClass(ContentEngine::class);
    $parameters = $reflection->getConstructor()?->getParameters() ?? [];
    $arguments = array_map(
        static function (ReflectionParameter $parameter): object {
            $type = $parameter->getType();

            expect($type)->toBeInstanceOf(ReflectionNamedType::class);

            assert($type instanceof ReflectionNamedType);

            return app($type->getName());
        },
        array_slice($parameters, 0, 22),
    );
    /** @var ContentEngine $content */
    $content = $reflection->newInstanceArgs($arguments);
    $owner = TestContentOwner::query()->create(['name' => 'Legacy Content service owner']);

    $editor = $content->editor(
        $owner,
        'homepage',
        ContentActorData::system(),
    );

    expect($editor->ownerType)->toBe('page')
        ->and($editor->ownerId)->toBe($owner->id)
        ->and($editor->group)->toBe('homepage')
        ->and($editor->placements)->toBe([]);
});

it('provides model-first grouped compositions through the facade and trait', function (): void {
    $actor = ContentActorData::system();
    $owner = TestContentOwner::query()->create(['name' => 'Grouped owner']);
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'grouped-hero',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Grouped title']],
        ),
        $actor,
    );
    $block = app(PublishContentBlockAction::class)->execute(
        $block,
        $block->revision,
        $actor,
    );

    $primaryPlacement = Content::place(
        $block,
        $owner,
        'primary',
        new PlaceContentBlockData(key: 'hero'),
        $actor,
    );
    expect(fn () => Content::place(
        $block,
        $owner,
        'secondary',
        new PlaceContentBlockData(
            key: 'child',
            parentId: $primaryPlacement->id,
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class);
    Content::place(
        $block,
        $owner,
        'secondary',
        new PlaceContentBlockData(key: 'hero'),
        $actor,
    );

    $primary = Content::render($owner, 'primary', 'en', $actor);
    $snapshot = Content::capture($owner, 'secondary', $actor);

    expect($owner->contentPlacements()->count())->toBe(2)
        ->and($owner->contentPlacements()->first()?->owner)->toBeInstanceOf(TestContentOwner::class)
        ->and(Content::groups($owner, $actor)->all())
        ->toBe(['default', 'homepage', 'main', 'primary', 'secondary'])
        ->and(Content::placements($owner, 'primary', $actor))->toHaveCount(1)
        ->and($primary->group)->toBe('primary')
        ->and($primary->value('hero.title'))->toBe('Grouped title')
        ->and($snapshot->group)->toBe('secondary')
        ->and(Content::renderSnapshot($snapshot, 'en', $actor)->group)->toBe('secondary');

    expect(fn () => Content::render($owner, 'undeclared', 'en', $actor))
        ->toThrow(InvalidArgumentException::class);

    $owner->delete();

    expect(ContentPlacement::query()->count())->toBe(0);
});

it('provides block reads through the same canonical application surface', function (): void {
    $actor = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'canonical-query',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Canonical query']],
        ),
        $actor,
    );

    expect(Content::block($block->id, $actor)->is($block))->toBeTrue()
        ->and(Content::blocks(FilterSet::none(), $actor)->total())->toBe(1);
});

it('resolves complete localized content through ordered bounded scope fallback', function (): void {
    $actor = ContentActorData::system();

    $fallback = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'heading',
            scope: 'site',
            scopeKey: 'default',
            translations: [
                'en' => ['title' => 'Fallback heading'],
                'bg' => ['title' => 'Заглавие по подразбиране'],
            ],
        ),
        $actor,
    );
    Content::publishBlock($fallback, $fallback->revision, $actor);

    $specific = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'heading',
            scope: 'site',
            scopeKey: 'tenant-a',
            translations: [
                'en' => ['title' => 'Specific heading'],
                'bg' => ['title' => 'Специфично заглавие'],
            ],
        ),
        $actor,
    );
    Content::publishBlock($specific, $specific->revision, $actor);

    $private = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'private-note',
            scope: 'site',
            scopeKey: 'tenant-a',
            visibility: ContentVisibility::Private,
            translations: ['en' => ['title' => 'Private note']],
        ),
        $actor,
    );
    Content::publishBlock($private, $private->revision, $actor);

    Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'draft-note',
            scope: 'site',
            scopeKey: 'tenant-a',
            translations: ['en' => ['title' => 'Draft note']],
        ),
        $actor,
    );

    $scopes = [
        new ContentScopeData('site', 'tenant-a'),
        new ContentScopeData('site', 'default'),
    ];
    $resolved = Content::resolveScopes($scopes, 'bg', $actor);
    $includingPrivate = Content::resolveScopes(
        $scopes,
        'en',
        $actor,
        publicOnly: false,
    );

    expect($resolved->values)->toHaveKeys(['heading'])
        ->and($resolved->values['heading']['title'])->toBe('Специфично заглавие')
        ->and($resolved->sources)->toBe(['heading' => 'site:tenant-a'])
        ->and($resolved->matched)->toBe(2)
        ->and($includingPrivate->values)->toHaveKeys(['heading', 'private-note'])
        ->and(fn () => Content::resolveScopes($scopes, 'en', $actor, limit: 1))
        ->toThrow(ContentScopeOverflowException::class)
        ->and(ContentBlock::filterSchema()->filter('scope')?->operators)
        ->toContain(FilterOperator::In);

    $invalidResolutions = [
        static fn () => Content::resolveScopes([], 'en', $actor),
        static fn () => Content::resolveScopes([new ContentScopeData('Invalid', 'tenant-a')], 'en', $actor),
        static fn () => Content::resolveScopes([
            new ContentScopeData('site', 'tenant-a'),
            new ContentScopeData('site', 'tenant-a'),
        ], 'en', $actor),
        static fn () => Content::resolveScopes($scopes, 'en', $actor, limit: 0),
    ];

    foreach ($invalidResolutions as $invalidResolution) {
        expect($invalidResolution)->toThrow(InvalidArgumentException::class);
    }
});

it('returns one complete typed editor bootstrap for a consumer-owned UI', function (): void {
    $actor = ContentActorData::system();
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'secondary-editor-schema',
        name: 'Secondary editor schema',
        description: null,
        category: 'content',
        version: 1,
        view: null,
        schema: ['fields' => []],
        allowedScopes: ['site'],
        allowedRegions: ['main'],
        sortOrder: 10,
    ));
    $owner = TestContentOwner::query()->create(['name' => 'Editor owner']);
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'editor-bootstrap',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Editor bootstrap']],
        ),
        $actor,
    );
    $placement = Content::place(
        $block,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'hero'),
        $actor,
    );

    $actionEditor = app(GetOwnerContentEditorAction::class)->execute(
        $owner,
        'homepage',
        $actor,
    );
    $editor = Content::editor($owner, 'homepage', $actor);

    expect($editor->ownerType)->toBe('page')
        ->and($editor->ownerId)->toBe($owner->id)
        ->and($editor->group)->toBe('homepage')
        ->and($editor->placementLimit)->toBe(1_000)
        ->and($editor->definitions[0]->key)->toBe('hero')
        ->and(collect($editor->definitions)->pluck('key')->all())
        ->toBe(['hero', 'secondary-editor-schema'])
        ->and(collect($editor->presets)->pluck('alias')->all())
        ->toBe(['banner', 'button', 'heading', 'image', 'link'])
        ->and($editor->groups)
        ->toBe(['default', 'homepage', 'main', 'primary', 'secondary'])
        ->and($editor->placements)->toHaveCount(1)
        ->and($editor->placements[0])->toBeInstanceOf(ContentPlacementData::class)
        ->and($editor->placements[0]->id)->toBe($placement->id)
        ->and($editor->placements[0]->block)->toBeInstanceOf(ContentBlockData::class)
        ->and($editor->placements[0]->block?->definition)->toBe('hero')
        ->and($editor->placements[0]->block?->translations['en']['title'] ?? null)
        ->toBe('Editor bootstrap')
        ->and($actionEditor->toArray())->toBe($editor->toArray());
});

it('loads the editor bootstrap in a constant query count', function (): void {
    $actor = ContentActorData::system();
    $owner = TestContentOwner::query()->create(['name' => 'Editor query owner']);
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'editor-query-block',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Editor query block']],
        ),
        $actor,
    );

    Content::place(
        $block,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'block-1'),
        $actor,
    );
    $measure = static function () use ($actor, $owner): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $editor = Content::editor($owner, 'homepage', $actor);
            $queryCount = count(DB::getQueryLog());
            collect($editor->placements)->each(static function (ContentPlacementData $placement): void {
                $placement->block?->translations;
            });

            expect(DB::getQueryLog())->toHaveCount($queryCount);

            return [$queryCount, count($editor->placements)];
        } finally {
            DB::disableQueryLog();
        }
    };
    [$singleQueryCount] = $measure();

    foreach (range(2, 20) as $index) {
        Content::place(
            $block,
            $owner,
            'homepage',
            new PlaceContentBlockData(key: "block-{$index}"),
            $actor,
        );
    }
    [$populatedQueryCount, $placementCount] = $measure();

    expect($placementCount)->toBe(20)
        ->and($singleQueryCount)->toBe(5)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

it('orders editor placements deterministically by region order and id', function (): void {
    $actor = ContentActorData::system();
    $owner = TestContentOwner::query()->create(['name' => 'Ordered editor owner']);
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'ordered-editor-block',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Ordered editor block']],
        ),
        $actor,
    );
    $sidebar = Content::place(
        $block,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'sidebar', region: 'sidebar', sortOrder: 0),
        $actor,
    );
    $later = Content::place(
        $block,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'later', region: 'main', sortOrder: 10),
        $actor,
    );
    $firstTie = Content::place(
        $block,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'first-tie', region: 'main', sortOrder: 5),
        $actor,
    );
    $secondTie = Content::place(
        $block,
        $owner,
        'homepage',
        new PlaceContentBlockData(key: 'second-tie', region: 'main', sortOrder: 5),
        $actor,
    );
    $tieIds = [$firstTie->id, $secondTie->id];
    sort($tieIds);

    $editor = app(GetOwnerContentEditorAction::class)->execute(
        $owner,
        'homepage',
        $actor,
    );

    expect(collect($editor->placements)->pluck('id')->all())->toBe([
        ...$tieIds,
        $later->id,
        $sidebar->id,
    ]);
});

it('bulk loads authorized owner placement summaries at a constant query count', function (): void {
    $system = ContentActorData::system();
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'bulk-summary-block',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Bulk summary block']],
        ),
        $system,
    );
    $owners = collect(range(1, 25))->map(
        static fn (int $index): TestContentOwner => TestContentOwner::query()->create([
            'name' => "Bulk owner {$index}",
        ]),
    );

    foreach ($owners as $index => $owner) {
        Content::place(
            $block,
            $owner,
            'homepage',
            new PlaceContentBlockData(
                key: "placement-{$index}",
                region: $index % 2 === 0 ? 'main' : 'sidebar',
                sortOrder: $index,
            ),
            $system,
        );
    }

    $authorization = new class implements ContentAuthorization
    {
        /** @var list<string> */
        public array $ownerIds = [];

        /** @var list<bool> */
        public array $includesBlocks = [];

        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {
            if ($ability === ContentAbility::ListPlacements && $owner !== null) {
                $this->ownerIds[] = (string) $owner->getKey();
                $this->includesBlocks[] = ($context['includes_blocks'] ?? null) === true;
            }
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    $actor = new ContentActorData('user', 'bulk-editor');
    $measure = static function (array $owners) use ($actor): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $summaries = app(ListOwnerContentPlacementSummariesAction::class)
                ->execute($owners, 'homepage', $actor);

            return [count(DB::getQueryLog()), $summaries];
        } finally {
            DB::disableQueryLog();
        }
    };

    [$singleQueryCount, $single] = $measure([$owners[0]]);
    $authorization->ownerIds = [];
    $authorization->includesBlocks = [];
    [$bulkQueryCount, $bulk] = $measure($owners->all());

    expect($single)->toHaveKey('page:'.$owners[0]->id)
        ->and($single['page:'.$owners[0]->id][0])->toBeInstanceOf(ContentPlacementData::class)
        ->and($single['page:'.$owners[0]->id][0]->block)->toBeInstanceOf(ContentBlockData::class)
        ->and($single['page:'.$owners[0]->id][0]->block?->translations['en']['title'] ?? null)
        ->toBe('Bulk summary block')
        ->and($bulk)->toHaveCount(25)
        ->and(array_keys($bulk))->toBe($owners->map(
            static fn (TestContentOwner $owner): string => 'page:'.$owner->id,
        )->all())
        ->and($authorization->ownerIds)->toBe($owners->pluck('id')->all())
        ->and($authorization->includesBlocks)->toBe(array_fill(0, 25, true))
        ->and($bulkQueryCount)->toBe($singleQueryCount);
});

it('uses serialization-safe canonical keys for mixed string and integer owners', function (): void {
    app(ContentOwnerRegistry::class)->register(
        'integer-page',
        TestIntegerContentOwner::class,
    );
    TestContentOwner::query()->forceCreate(['id' => '1', 'name' => 'String one']);
    $stringOwner = TestContentOwner::query()->findOrFail('1');
    $integerOwner = TestIntegerContentOwner::query()->create(['name' => 'Integer one']);

    expect($integerOwner->getKey())->toBe(1);

    $summaries = app(ListOwnerContentPlacementSummariesAction::class)->execute(
        [$stringOwner, $integerOwner],
        'default',
        ContentActorData::system(),
    );

    expect(array_keys($summaries))->toBe(['page:1', 'integer-page:1'])
        ->and(json_encode($summaries, JSON_THROW_ON_ERROR))->toStartWith('{');
});

it('bounds and short circuits bulk owner placement summaries', function (): void {
    $actor = ContentActorData::system();

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(app(ListOwnerContentPlacementSummariesAction::class)
            ->execute([], 'homepage', $actor))->toBe([])
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }

    $owners = collect(range(1, 101))->map(
        static fn (int $index): TestContentOwner => TestContentOwner::query()->create([
            'name' => "Bounded owner {$index}",
        ]),
    );

    expect(fn () => app(ListOwnerContentPlacementSummariesAction::class)
        ->execute($owners, 'homepage', $actor))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(ListOwnerContentPlacementSummariesAction::class)
        ->execute(array_fill(0, 101, $owners[0]), 'homepage', $actor))
        ->toThrow(InvalidArgumentException::class);
});

it('authorizes every bulk summary owner before querying package or owner storage', function (): void {
    $owners = collect(range(1, 2))->map(
        static fn (int $index): TestContentOwner => TestContentOwner::query()->create([
            'name' => "Denied owner {$index}",
        ]),
    );
    $authorization = new class implements ContentAuthorization
    {
        public int $calls = 0;

        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {
            if ($ability !== ContentAbility::ListPlacements) {
                return;
            }

            $this->calls++;

            if ($this->calls === 2) {
                throw new AuthorizationException;
            }
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(fn () => app(ListOwnerContentPlacementSummariesAction::class)->execute(
            [$owners[0], $owners[0], $owners[1]],
            'homepage',
            new ContentActorData('user', 'denied-editor'),
        ))->toThrow(AuthorizationException::class)
            ->and($authorization->calls)->toBe(2)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('makes placed block disclosure explicit without changing legacy placement context', function (): void {
    $owner = TestContentOwner::query()->create(['name' => 'Projection policy owner']);
    $authorization = new class implements ContentAuthorization
    {
        /** @var list<array<string, mixed>> */
        public array $contexts = [];

        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {
            if ($ability === ContentAbility::ListPlacements) {
                $this->contexts[] = $context;
            }
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    $action = app(ListContentPlacementsAction::class);
    $actor = new ContentActorData('user', 'projection-policy');

    $action->execute($owner, 'homepage', $actor);
    $action->execute($owner, 'homepage', $actor, includeBlocks: true);

    expect($authorization->contexts)->toBe([
        ['group' => 'homepage'],
        ['group' => 'homepage', 'includes_blocks' => true],
    ]);
});

it('authorizes editor catalogs before reading placement or block storage', function (): void {
    $owner = TestContentOwner::query()->create(['name' => 'Denied editor owner']);
    $authorization = new class implements ContentAuthorization
    {
        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {
            if ($ability === ContentAbility::ListDefinitions) {
                throw new AuthorizationException;
            }
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(fn () => app(GetOwnerContentEditorAction::class)->execute(
            $owner,
            'homepage',
            new ContentActorData('user', 'denied-editor-catalog'),
        ))->toThrow(AuthorizationException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('rejects stale owners and per-owner placement overflow in bulk summaries', function (): void {
    $actor = ContentActorData::system();
    $stale = TestContentOwner::query()->create(['name' => 'Stale owner']);
    TestContentOwner::query()->whereKey($stale->id)->delete();

    expect(fn () => app(ListOwnerContentPlacementSummariesAction::class)
        ->execute([$stale], 'homepage', $actor))
        ->toThrow(InvalidArgumentException::class);

    $owner = TestContentOwner::query()->create(['name' => 'Overflow owner']);
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'overflow-summary-block',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Overflow summary block']],
        ),
        $actor,
    );

    foreach (range(1, 2) as $index) {
        Content::place(
            $block,
            $owner,
            'homepage',
            new PlaceContentBlockData(key: "overflow-{$index}"),
            $actor,
        );
    }

    config()->set('content.placements.maximum_per_group', 1);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(fn () => app(ListOwnerContentPlacementSummariesAction::class)
            ->execute([$owner], 'homepage', $actor))
            ->toThrow(InvalidArgumentException::class);
        $placementQuery = collect(DB::getQueryLog())->first(
            static fn (array $query): bool => str_contains(
                $query['query'],
                'content_placements',
            ),
        );

        expect($placementQuery['query'] ?? null)->toContain('limit 2');
    } finally {
        DB::disableQueryLog();
    }
});

it('reports persisted placements outside their owner group declaration', function (): void {
    $actor = ContentActorData::system();
    $owner = TestContentOwner::query()->create(['name' => 'Group drift']);
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'group-drift',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Group drift']],
        ),
        $actor,
    );
    $placement = Content::place(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(key: 'hero'),
        $actor,
    );
    ContentPlacement::query()
        ->whereKey($placement->id)
        ->update(['group' => 'undeclared']);

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"placements.declared_groups": false');
});

it('stores only explicitly overridden nested placement leaves', function (): void {
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'nested-overrides',
        name: 'Nested overrides',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'settings',
                'type' => 'object',
                'label' => 'Settings',
                'fields' => [
                    ['key' => 'theme', 'type' => 'text', 'label' => 'Theme'],
                    ['key' => 'gap', 'type' => 'integer', 'label' => 'Gap'],
                ],
            ]],
        ],
        allowedScopes: ['site'],
    ));
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());

    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'nested-overrides',
            key: 'nested-overrides',
            scope: 'site',
            scopeKey: 'main-site',
            values: ['settings' => ['theme' => 'light', 'gap' => 4]],
        ),
        ContentActorData::system(),
    );
    $owner = TestContentOwner::query()->create(['name' => 'Overrides page']);
    $placement = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'nested',
            overrides: ['settings' => ['gap' => 8]],
        ),
        ContentActorData::system(),
    );

    expect($placement->overrides)->toBe(['settings' => ['gap' => 8]]);
});

it('compiles definitions strictly before registration', function (): void {
    $registry = app(ContentDefinitionRegistry::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'unknown-field-property',
        name: 'Unknown field property',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'title',
                'type' => 'text',
                'label' => 'Title',
                'requird' => true,
            ]],
        ],
    )))->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'coerced-boolean',
        name: 'Coerced boolean',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'title',
                'type' => 'text',
                'label' => 'Title',
                'required' => 'false',
            ]],
        ],
    )))->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'irrelevant-children',
        name: 'Irrelevant children',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'title',
                'type' => 'text',
                'label' => 'Title',
                'fields' => [
                    ['key' => 'child', 'type' => 'text', 'label' => 'Child'],
                ],
            ]],
        ],
    )))->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'invalid-json-schema',
        name: 'Invalid JSON Schema',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'payload',
                'type' => 'json',
                'label' => 'Payload',
                'settings' => ['schema' => ['type' => 123]],
            ]],
        ],
    )))->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'missing-reference-resolver',
        name: 'Missing reference resolver',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'resource',
                'type' => 'reference',
                'label' => 'Resource',
                'settings' => ['reference_type' => 'missing'],
            ]],
        ],
    )))->toThrow(InvalidArgumentException::class);

    config()->set([
        'content.definitions' => [
            'unknown-definition-property' => [
                'name' => 'Unknown definition property',
                'schema' => ['fields' => []],
                'versoin' => 2,
            ],
        ],
        'content.definition_paths' => [],
    ]);

    expect(fn () => app(ContentDefinitionLoader::class)->load())
        ->toThrow(InvalidArgumentException::class);
});

it('validates definition defaults without resolving external resources at boot', function (): void {
    $registry = app(ContentDefinitionRegistry::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'invalid-defaults',
        name: 'Invalid defaults',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'enabled',
                'type' => 'boolean',
                'label' => 'Enabled',
            ]],
        ],
        defaults: ['enabled' => 'yes'],
        allowedScopes: ['site'],
    )))->toThrow(InvalidArgumentException::class);

    $registry->register(new ContentDefinitionSource(
        key: 'external-default-shape',
        name: 'External default shape',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'image',
                'type' => 'media',
                'label' => 'Image',
            ]],
        ],
        defaults: ['image' => '550e8400-e29b-41d4-a716-446655440000'],
        allowedScopes: ['site'],
    ));

    expect($registry->get('external-default-shape')->defaults)->toBe([
        'image' => '550e8400-e29b-41d4-a716-446655440000',
    ]);
});

it('validates recursive schema field defaults without requiring payload values', function (): void {
    $registry = app(ContentDefinitionRegistry::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'invalid-localized-field-default',
        name: 'Invalid localized field default',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'title',
                'type' => 'boolean',
                'label' => 'Title',
                'localized' => true,
                'default' => 'yes',
            ]],
        ],
        allowedScopes: ['site'],
    )))->toThrow(InvalidArgumentException::class);
});

it('validates nested field defaults when their parent has no payload default', function (): void {
    $registry = app(ContentDefinitionRegistry::class);

    expect(fn () => $registry->register(new ContentDefinitionSource(
        key: 'invalid-nested-field-default',
        name: 'Invalid nested field default',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [[
                'key' => 'settings',
                'type' => 'object',
                'label' => 'Settings',
                'fields' => [[
                    'key' => 'enabled',
                    'type' => 'boolean',
                    'label' => 'Enabled',
                    'default' => 'yes',
                ]],
            ]],
        ],
        allowedScopes: ['site'],
    )))->toThrow(InvalidArgumentException::class);
});

it('keeps valid external schema field defaults shape-only at boot', function (): void {
    $registry = app(ContentDefinitionRegistry::class);

    $registry->register(new ContentDefinitionSource(
        key: 'valid-recursive-field-defaults',
        name: 'Valid recursive field defaults',
        description: null,
        category: 'testing',
        version: 1,
        view: null,
        schema: [
            'fields' => [
                [
                    'key' => 'enabled',
                    'type' => 'boolean',
                    'label' => 'Enabled',
                    'localized' => true,
                    'default' => true,
                ],
                [
                    'key' => 'image',
                    'type' => 'media',
                    'label' => 'Image',
                    'localized' => true,
                    'default' => '550e8400-e29b-41d4-a716-446655440000',
                ],
                [
                    'key' => 'article',
                    'type' => 'reference',
                    'label' => 'Article',
                    'localized' => true,
                    'default' => 'missing-article',
                    'settings' => ['reference_type' => 'article'],
                ],
                [
                    'key' => 'settings',
                    'type' => 'object',
                    'label' => 'Settings',
                    'fields' => [[
                        'key' => 'visible',
                        'type' => 'boolean',
                        'label' => 'Visible',
                        'default' => false,
                    ]],
                ],
            ],
        ],
        allowedScopes: ['site'],
    ));

    $fields = $registry->get('valid-recursive-field-defaults')->schema->fields;

    expect($fields[0]->default)->toBeTrue()
        ->and($fields[1]->default)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($fields[2]->default)->toBe('missing-article')
        ->and($fields[3]->fields[0]->default)->toBeFalse();
});

it('rejects ambiguous and unsupported translation locale keys', function (): void {
    config()->set([
        'content.locales.available' => ['en-US'],
        'translatable.locales' => ['en-US'],
    ]);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'duplicate-locales',
            scope: 'site',
            scopeKey: 'main-site',
            translations: [
                'en_US' => ['title' => 'First'],
                'en-US' => ['title' => 'Second'],
            ],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);

    config()->set([
        'content.locales.available' => [],
        'translatable.locales' => ['en', 'bg'],
    ]);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'unsupported-locale',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['fr' => ['title' => 'Bonjour']],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);
});

it('returns stable unprocessable API errors for semantic content failures', function (): void {
    config()->set([
        'content.routes.management.enabled' => true,
        'content.routes.management.prefix' => 'api/content-contract',
        'content.routes.management.name' => 'content.contract.',
        'content.routes.management.middleware' => [],
    ]);
    require __DIR__.'/../../routes/api.php';
    app('router')->getRoutes()->refreshNameLookups();

    $created = $this->postJson('/api/content-contract/blocks', [
        'definition' => 'hero',
        'key' => 'object-fidelity',
        'scope' => 'site',
        'scopeKey' => 'main-site',
    ])->assertCreated();

    expect($created->getContent())
        ->toContain('"values":{"enabled":true}')
        ->toContain('"translations":{}')
        ->toContain('"metadata":{}');

    $this->postJson('/api/content-contract/blocks', [
        'definition' => 'hero',
        'key' => 'invalid-json-schema-value',
        'scope' => 'site',
        'scopeKey' => 'main-site',
        'values' => ['layout' => ['columns' => 0]],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_content')
        ->assertJsonStructure([
            'message',
            'error' => ['code', 'context'],
        ]);
});

it('bounds arbitrary JSON structures recursively', function (): void {
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'bounded-json',
        name: 'Bounded JSON',
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
                        '$id' => 'https://schemas.example.test/bounded-json',
                        'type' => 'object',
                    ],
                ],
            ]],
        ],
        allowedScopes: ['site'],
    ));
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
    config()->set('content.validation.maximum_depth', 3);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'bounded-json',
            key: 'deep-json',
            scope: 'site',
            scopeKey: 'main-site',
            values: ['payload' => ['one' => ['two' => ['three' => true]]]],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);
});

it('validates exact scalar formats and option values', function (): void {
    $context = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'field',
        ContentVisibility::Private,
    );

    expect(fn () => (new StringFieldTypeAdapter('color'))->normalize(
        '#12345',
        new ContentFieldDefinition('color', 'color', 'Color'),
        $context,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new StringFieldTypeAdapter('date_time'))->normalize(
        '2026-02-31T10:00:00+00:00',
        new ContentFieldDefinition('starts_at', 'date_time', 'Starts at'),
        $context,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new StringFieldTypeAdapter('select'))->normalize(
        'Published',
        new ContentFieldDefinition(
            'status',
            'select',
            'Status',
            settings: ['options' => ['published' => 'Published']],
        ),
        $context,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new StringFieldTypeAdapter('uri'))->normalize(
        '/safe\..\admin',
        new ContentFieldDefinition('href', 'uri', 'Link'),
        $context,
    ))->toThrow(InvalidArgumentException::class);
});

it('hard-denies unsafe semantic destinations despite consumer scheme allowlists', function (
    string $scheme,
    string $destination,
): void {
    config()->set('content.links.allowed_schemes', ['https', $scheme]);
    $context = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'link.href',
        ContentVisibility::Private,
    );

    expect(fn () => (new StringFieldTypeAdapter('uri'))->normalize(
        $destination,
        new ContentFieldDefinition('href', 'uri', 'Destination'),
        $context,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'javascript' => ['javascript', 'javascript:alert(1)'],
    'data' => ['data', 'data:text/html,unsafe'],
    'vbscript' => ['vbscript', 'vbscript:msgbox(1)'],
    'file' => ['file', 'file:///etc/passwd'],
]);

it('removes executable rich-text links despite runtime scheme configuration', function (): void {
    config()->set(
        'content.rich_text.allowed_link_schemes',
        ['https', 'javascript'],
    );
    $adapter = new RichTextFieldTypeAdapter;
    $context = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'body',
        ContentVisibility::Private,
    );
    $sanitized = $adapter->normalize(
        '<p><a href="javascript:alert(1)">Unsafe</a></p>',
        new ContentFieldDefinition('body', 'rich_text', 'Body'),
        $context,
    );

    expect($sanitized)->toBeString()
        ->not->toContain('javascript:')
        ->not->toContain('href=');
});

it('rejects unsafe global scheme configuration during package bootstrap', function (
    string $key,
): void {
    config()->set($key, ['https', 'javascript']);

    expect(fn () => (new ContentServiceProvider(app()))->register())
        ->toThrow(InvalidArgumentException::class);
})->with([
    'semantic links' => 'content.links.allowed_schemes',
    'URL fields' => 'content.validation.url_schemes',
    'rich text links' => 'content.rich_text.allowed_link_schemes',
]);

it('rejects duplicate repeater keys', function (): void {
    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'duplicate-repeater-keys',
            scope: 'site',
            scopeKey: 'main-site',
            values: [
                'links' => [
                    ['_key' => 'same', 'label' => 'One'],
                    ['_key' => 'same', 'label' => 'Two'],
                ],
            ],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);
});

it('requires localized repeater rows to reference stable existing base keys', function (): void {
    $schema = ContentSchema::fromArray([
        [
            'key' => 'items',
            'type' => 'repeater',
            'label' => 'Items',
            'fields' => [
                ['key' => 'slug', 'type' => 'text', 'label' => 'Slug'],
                [
                    'key' => 'label',
                    'type' => 'text',
                    'label' => 'Label',
                    'localized' => true,
                ],
            ],
        ],
    ]);
    $values = [
        'items' => [
            ['_key' => 'first', 'slug' => 'first'],
            ['_key' => 'second', 'slug' => 'second'],
        ],
    ];
    $validator = app(ContentValueValidator::class);

    expect(fn () => $validator->validate(
        schema: $schema,
        values: $values,
        translations: ['bg' => ['items' => [['label' => 'Първи']]]],
        actor: ContentActorData::system(),
        visibility: ContentVisibility::Private,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $validator->validate(
        schema: $schema,
        values: $values,
        translations: [
            'bg' => [
                'items' => [
                    ['_key' => 'unknown', 'label' => 'Непознат'],
                ],
            ],
        ],
        actor: ContentActorData::system(),
        visibility: ContentVisibility::Private,
    ))->toThrow(InvalidArgumentException::class);
});

it('matches reordered partial repeater translations by stable row key', function (): void {
    $schema = ContentSchema::fromArray([
        [
            'key' => 'items',
            'type' => 'repeater',
            'label' => 'Items',
            'fields' => [
                ['key' => 'slug', 'type' => 'text', 'label' => 'Slug'],
                [
                    'key' => 'label',
                    'type' => 'text',
                    'label' => 'Label',
                    'localized' => true,
                ],
            ],
        ],
    ]);
    $validated = app(ContentValueValidator::class)->validate(
        schema: $schema,
        values: [
            'items' => [
                ['_key' => 'first', 'slug' => 'first'],
                ['_key' => 'second', 'slug' => 'second'],
                ['_key' => 'third', 'slug' => 'third'],
            ],
        ],
        translations: [
            'bg' => [
                'items' => [
                    ['_key' => 'third', 'label' => 'Трети'],
                    ['_key' => 'first', 'label' => 'Първи'],
                ],
            ],
        ],
        actor: ContentActorData::system(),
        visibility: ContentVisibility::Private,
    );
    $localized = $validated->translations['bg'];
    $overlaid = app(ContentLocalizedValues::class)->overlay(
        $schema,
        $validated->values,
        $localized,
    );

    expect(array_column($localized['items'], '_key'))->toBe(['third', 'first'])
        ->and($overlaid['items'])->toBe([
            ['_key' => 'first', 'slug' => 'first', 'label' => 'Първи'],
            ['_key' => 'second', 'slug' => 'second'],
            ['_key' => 'third', 'slug' => 'third', 'label' => 'Трети'],
        ]);
});

it('accepts empty PHP arrays for objects containing only localized descendants', function (): void {
    $schema = ContentSchema::fromArray([
        [
            'key' => 'copy',
            'type' => 'object',
            'label' => 'Copy',
            'fields' => [
                [
                    'key' => 'title',
                    'type' => 'text',
                    'label' => 'Title',
                    'localized' => true,
                ],
            ],
        ],
    ]);
    $validated = app(ContentValueValidator::class)->validate(
        schema: $schema,
        values: ['copy' => []],
        translations: ['bg' => ['copy' => []]],
        actor: ContentActorData::system(),
        visibility: ContentVisibility::Private,
    );

    expect($validated->values)->toBe(['copy' => []])
        ->and($validated->translations)->toBe(['bg' => ['copy' => []]]);
});

it('rechecks payload bounds after defaults expand normalized values', function (): void {
    config()->set('content.validation.maximum_payload_bytes', 64);

    $schema = ContentSchema::fromArray([
        [
            'key' => 'message',
            'type' => 'text',
            'label' => 'Message',
            'default' => str_repeat('x', 80),
        ],
    ]);

    expect(fn () => app(ContentValueValidator::class)->validate(
        schema: $schema,
        values: [],
        translations: [],
        actor: ContentActorData::system(),
        visibility: ContentVisibility::Private,
    ))->toThrow(InvalidArgumentException::class);
});

it('reports unsynchronized orphan definitions in doctor diagnostics', function (): void {
    ContentDefinition::query()->create([
        'key' => 'removed-definition',
        'name' => 'Removed definition',
        'category' => 'testing',
        'version' => 1,
        'schema' => ['fields' => []],
        'allowed_scopes' => ['site'],
        'allowed_regions' => ['main'],
        'is_active' => true,
        'source_hash' => str_repeat('a', 64),
        'synced_at' => now(),
    ]);

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"definitions.synchronized": false');

    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"definitions.synchronized": true');
});

it('exposes the registered reference aliases without resolving them', function (): void {
    $registry = new ContentReferenceRegistry(
        app(),
        app(ContentPayloadGuard::class),
    );

    expect($registry->has('missing'))->toBeFalse();
});

it('requires definition version progression for contract changes', function (): void {
    $definition = ContentDefinition::query()->where('key', 'hero')->firstOrFail();
    $definition->forceFill([
        'schema' => ['fields' => []],
        'source_hash' => str_repeat('b', 64),
    ])->save();

    expect(fn () => app(SyncContentDefinitionsAction::class)->execute(
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);
});

it('allows same-version definition mirror repair when the contract is unchanged', function (): void {
    $definition = ContentDefinition::query()->where('key', 'hero')->firstOrFail();
    $definition->forceFill(['source_hash' => str_repeat('c', 64)])->save();

    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());

    expect($definition->refresh()->source_hash)->not->toBe(str_repeat('c', 64))
        ->and($definition->version)->toBe(2);
});

it('rechecks the synchronized definition under lock before creating a block', function (): void {
    $mutated = false;

    DB::listen(function (QueryExecuted $query) use (&$mutated): void {
        if ($mutated
            || ! str_contains(strtolower($query->sql), 'select')
            || ! str_contains($query->sql, 'content_definitions')) {
            return;
        }

        $mutated = true;
        ContentDefinition::query()
            ->where('key', 'hero')
            ->update(['source_hash' => str_repeat('0', 64)]);
    });

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'definition-lock',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Definition lock']],
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);

    expect(ContentBlock::query()->where('key', 'definition-lock')->exists())->toBeFalse();
});

it('replans definition synchronization after acquiring database locks', function (): void {
    $mutated = false;

    DB::listen(function (QueryExecuted $query) use (&$mutated): void {
        if ($mutated
            || ! str_contains(strtolower($query->sql), 'select')
            || ! str_contains($query->sql, 'content_definitions')) {
            return;
        }

        $mutated = true;
        ContentDefinition::query()
            ->where('key', 'hero')
            ->update([
                'version' => 3,
                'source_hash' => str_repeat('f', 64),
            ]);
    });

    expect(fn () => app(SyncContentDefinitionsAction::class)->execute(
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);

    expect(ContentDefinition::query()->where('key', 'hero')->value('version'))->toBe(3);
});

it('serializes definition synchronization across deployment processes', function (): void {
    config()->set('content.definition_sync.lock_wait_seconds', 1);
    $store = app(Repository::class)->getStore();

    expect($store)->toBeInstanceOf(LockProvider::class);

    if (! $store instanceof LockProvider) {
        return;
    }

    $lock = $store->lock('nvl:content:definitions:sync', 10);

    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(SyncContentDefinitionsAction::class)->execute(
            ContentActorData::system(),
        ))->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});

it('applies one placement depth definition consistently', function (): void {
    config()->set('content.placements.maximum_depth', 2);
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'depth',
            scope: 'site',
            scopeKey: 'main-site',
        ),
        ContentActorData::system(),
    );
    $owner = TestContentOwner::query()->create(['name' => 'Depth page']);
    $root = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'root',
        ),
        ContentActorData::system(),
    );
    $child = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'child',
            parentId: $root->id,
        ),
        ContentActorData::system(),
    );

    expect($child->parent_id)->toBe($root->id);
    expect(fn () => app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'grandchild',
            parentId: $child->id,
        ),
        ContentActorData::system(),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects reparenting a subtree beyond the placement depth limit', function (): void {
    config()->set('content.placements.maximum_depth', 3);
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'subtree-depth',
            scope: 'site',
            scopeKey: 'main-site',
        ),
        $actor,
    );
    $owner = TestContentOwner::query()->create(['name' => 'Subtree depth page']);
    $subtreeRoot = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(key: 'subtree-root'),
        $actor,
    );
    app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'subtree-child',
            parentId: $subtreeRoot->id,
        ),
        $actor,
    );
    $targetRoot = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(key: 'target-root'),
        $actor,
    );
    $targetChild = app(PlaceContentBlockAction::class)->execute(
        $block,
        $owner,
        'default',
        new PlaceContentBlockData(
            key: 'target-child',
            parentId: $targetRoot->id,
        ),
        $actor,
    );

    expect(fn () => app(UpdateContentPlacementAction::class)->execute(
        $subtreeRoot,
        new UpdateContentPlacementData(
            expectedRevision: $subtreeRoot->revision,
            region: 'main',
            parentId: $targetChild->id,
            sortOrder: 0,
            isVisible: true,
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class);

    expect($subtreeRoot->refresh()->parent_id)->toBeNull();
});

it('isolates reference display caches by the complete resolver context', function (): void {
    $adapter = new ReferenceFieldTypeAdapter(
        multiple: false,
        references: app(ContentReferenceRegistry::class),
    );
    $field = new ContentFieldDefinition(
        key: 'article',
        type: 'reference',
        label: 'Article',
        settings: ['reference_type' => 'article'],
    );
    $actor = ContentActorData::system();
    $resources = new ContentRenderResources;
    $primary = $adapter->render(
        'article-1',
        $field,
        new ContentValidationContext(
            actor: $actor,
            locale: 'en',
            path: 'primary.article',
            visibility: ContentVisibility::Public,
            resources: $resources,
            publicOnly: true,
            group: 'default',
        ),
    );
    $secondary = $adapter->render(
        'article-1',
        $field,
        new ContentValidationContext(
            actor: $actor,
            locale: 'en',
            path: 'secondary.article',
            visibility: ContentVisibility::Public,
            resources: $resources,
            publicOnly: true,
            group: 'default',
        ),
    );
    $privatePreview = $adapter->render(
        'article-1',
        $field,
        new ContentValidationContext(
            actor: $actor,
            locale: 'en',
            path: 'primary.article',
            visibility: ContentVisibility::Private,
            resources: $resources,
            publicOnly: false,
            group: 'default',
        ),
    );

    expect($primary)->toBeArray()
        ->and($secondary)->toBeArray()
        ->and($privatePreview)->toBeArray()
        ->and($primary['path'])->toBe('primary.article')
        ->and($secondary['path'])->toBe('secondary.article')
        ->and($privatePreview['visibility'])->toBe(ContentVisibility::Private->value)
        ->and($privatePreview['public_only'])->toBeFalse();
});

it('replaces configured lists while recursively filling missing map defaults', function (): void {
    config()->set('content', [
        'links' => [
            'allowed_schemes' => ['https'],
        ],
        'routes' => [
            'public' => [
                'middleware' => [],
            ],
        ],
    ]);

    (new ContentServiceProvider(app()))->register();

    expect(config('content.links.allowed_schemes'))->toBe(['https'])
        ->and(config('content.links.allow_relative'))->toBeTrue()
        ->and(config('content.routes.public.middleware'))->toBe([])
        ->and(config('content.routes.public.enabled'))->toBeFalse();
});

it('fails strict diagnostics for a default view rejected by live rendering', function (): void {
    config()->set('content.rendering.default_view', '');

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"view.default": false');
});

it('validates definition synchronization lock configuration', function (string $key): void {
    config()->set("content.definition_sync.{$key}", 0);

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"cache.definition_sync_locks": true');

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"routes.configuration": false');
})->with([
    'lease duration' => 'lock_seconds',
    'wait duration' => 'lock_wait_seconds',
]);

it('rejects required database columns with incompatible semantics', function (string $semantic): void {
    if ($semantic === 'type' && DB::getDriverName() === 'pgsql') {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable(ContentConfiguration::table('blocks'));
        $column = $grammar->wrap('key');
        DB::statement("alter table {$table} alter column {$column} type integer using 0");
    } else {
        Schema::table(ContentConfiguration::table('blocks'), function (Blueprint $table) use ($semantic): void {
            if ($semantic === 'type') {
                $table->integer('key')->change();

                return;
            }

            if ($semantic === 'nullability') {
                $table->string('key', 191)->nullable()->change();

                return;
            }

            $table->string('status', 30)->default('published')->change();
        });
    }

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"schema.columns": false');
})->with([
    'incompatible type' => 'type',
    'incompatible nullability' => 'nullability',
    'incompatible default' => 'default',
]);

it('rejects same-named database indexes with incompatible semantics', function (): void {
    $table = ContentConfiguration::table('blocks');
    Schema::table($table, function (Blueprint $table): void {
        $table->dropIndex('content_blocks_scope_state_idx');
    });
    Schema::table($table, function (Blueprint $table): void {
        $table->index(['key'], 'content_blocks_scope_state_idx');
    });

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"schema.indexes": false');
});

it('rejects schemas missing required foreign key semantics', function (): void {
    Schema::table(ContentConfiguration::table('blocks_i18n'), function (Blueprint $table): void {
        $table->dropForeign(['content_block_id']);
    });

    $this->artisan('nvl:content:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed()
        ->expectsOutputToContain('"schema.foreign_keys": false');
});

it('rejects conflicting keyed definition identities and normalized property aliases', function (): void {
    config()->set('content.definition_paths', []);
    config()->set('content.definitions', [
        'outer-key' => [
            'key' => 'inner-key',
            'name' => 'Conflicting identity',
            'schema' => ['fields' => []],
        ],
    ]);

    expect(fn () => app(ContentDefinitionLoader::class)->load())
        ->toThrow(InvalidArgumentException::class);

    config()->set('content.definitions', [[
        'key' => 'conflicting-aliases',
        'name' => 'Conflicting aliases',
        'allowed_scopes' => ['global'],
        'allowedScopes' => ['site'],
        'schema' => ['fields' => []],
    ]]);

    expect(fn () => app(ContentDefinitionLoader::class)->load())
        ->toThrow(InvalidArgumentException::class);
});

it('canonicalizes model block reads and does not load unused placements', function (): void {
    $actor = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'canonical-model-read',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Canonical model read']],
        ),
        $actor,
    );
    $read = app(GetContentBlockAction::class)->execute($block, $actor);

    expect($read->relationLoaded('placements'))->toBeFalse();

    ContentBlock::query()->whereKey($block->id)->delete();
    $deleted = ContentBlock::withTrashed()->findOrFail($block->id);

    expect(fn () => app(GetContentBlockAction::class)->execute($deleted, $actor))
        ->toThrow(ModelNotFoundException::class);
});

it('applies trusted actor scope before caller-controlled block filters', function (): void {
    $system = ContentActorData::system();

    foreach (['tenant-a', 'tenant-b'] as $tenant) {
        app(CreateContentBlockAction::class)->execute(
            new CreateContentBlockData(
                definition: 'hero',
                key: "catalog-{$tenant}",
                scope: 'site',
                scopeKey: $tenant,
                translations: ['en' => ['title' => "Catalog {$tenant}"]],
                metadata: ['tenant' => $tenant],
            ),
            $system,
        );
    }

    $authorization = new class implements ContentAuthorization, ContentBlockQueryScope
    {
        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {}

        /**
         * @param  Builder<ContentBlock>  $query
         */
        public function scopeContentBlocks(Builder $query, ContentActorData $actor): void
        {
            $query->where('scope_key', $actor->id);
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    $actor = new ContentActorData('tenant', 'tenant-a');
    $page = app(ListContentBlocksAction::class)->execute(FilterSet::none(), $actor);
    $forged = app(ListContentBlocksAction::class)->execute(
        new FilterSet(filters: [
            new FilterCriterion(
                'scope_key',
                FilterOperator::Equals,
                'tenant-b',
            ),
        ]),
        $actor,
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->scope_key)->toBe('tenant-a')
        ->and($forged->total())->toBe(0);
});

it('provides complete proposed create state to authorization', function (): void {
    $authorization = new class implements ContentAuthorization
    {
        /** @var list<array<string, mixed>> */
        public array $createContexts = [];

        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {
            if ($ability !== ContentAbility::Create) {
                return;
            }

            $this->createContexts[] = $context;

            if (($context['scope_key'] ?? null) !== 'tenant-a'
                || ($context['visibility'] ?? null) !== ContentVisibility::Private->value) {
                throw new AuthorizationException;
            }
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    $actor = new ContentActorData('tenant', 'tenant-a');
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'authorized-create',
            scope: 'site',
            scopeKey: 'tenant-a',
            visibility: ContentVisibility::Private,
            translations: ['en' => ['title' => 'Authorized create']],
        ),
        $actor,
    );

    expect($block->scope_key)->toBe('tenant-a')
        ->and($authorization->createContexts[0])->toMatchArray([
            'definition' => 'hero',
            'key' => 'authorized-create',
            'scope' => 'site',
            'scope_key' => 'tenant-a',
            'visibility' => ContentVisibility::Private->value,
            'status' => ContentStatus::Draft->value,
        ]);

    expect(fn () => app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'unauthorized-create',
            scope: 'site',
            scopeKey: 'tenant-a',
            visibility: ContentVisibility::Public,
        ),
        $actor,
    ))->toThrow(AuthorizationException::class);

    expect(ContentBlock::query()->where('key', 'unauthorized-create')->exists())->toBeFalse();
});

it('requires publish authorization before exposing a live private block', function (): void {
    $system = ContentActorData::system();
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'visibility-transition',
            scope: 'site',
            scopeKey: 'tenant-a',
            visibility: ContentVisibility::Private,
            translations: ['en' => ['title' => 'Visibility transition']],
        ),
        $system,
    );
    $block = app(PublishContentBlockAction::class)->execute(
        $block,
        $block->revision,
        $system,
    );
    $authorization = new class implements ContentAuthorization
    {
        public bool $allowPublish = false;

        /** @var list<array{ability: ContentAbility, context: array<string, mixed>}> */
        public array $calls = [];

        public function authorize(
            ContentAbility $ability,
            ContentActorData $actor,
            ?ContentBlock $block = null,
            ?Model $owner = null,
            array $context = [],
        ): void {
            $this->calls[] = ['ability' => $ability, 'context' => $context];

            if ($ability === ContentAbility::Publish && ! $this->allowPublish) {
                throw new AuthorizationException;
            }
        }
    };
    app()->instance(ContentAuthorization::class, $authorization);
    $actor = new ContentActorData('user', 'editor-1');

    expect(fn () => app(UpdateContentBlockAction::class)->execute(
        $block,
        new UpdateContentBlockData(
            expectedRevision: $block->revision,
            visibility: ContentVisibility::Public,
        ),
        $actor,
    ))->toThrow(AuthorizationException::class);

    expect($block->refresh()->visibility)->toBe(ContentVisibility::Private)
        ->and($block->revision)->toBe(2)
        ->and($authorization->calls)->toHaveCount(2)
        ->and($authorization->calls[0]['ability'])->toBe(ContentAbility::Update)
        ->and($authorization->calls[0]['context'])->toMatchArray([
            'definition' => 'hero',
            'key' => 'visibility-transition',
            'scope' => 'site',
            'scope_key' => 'tenant-a',
            'current_visibility' => ContentVisibility::Private->value,
            'target_visibility' => ContentVisibility::Public->value,
            'published' => true,
        ])
        ->and($authorization->calls[1]['ability'])->toBe(ContentAbility::Publish)
        ->and($authorization->calls[1]['context']['visibility_transition'])->toBeTrue();

    $authorization->allowPublish = true;
    $updated = app(UpdateContentBlockAction::class)->execute(
        $block,
        new UpdateContentBlockData(
            expectedRevision: $block->revision,
            visibility: ContentVisibility::Public,
        ),
        $actor,
    );

    expect($updated->visibility)->toBe(ContentVisibility::Public)
        ->and($updated->revision)->toBe(3);
});

it('fails closed instead of adopting a pre-existing package table', function (
    string $tableKey,
    string $migrationFile,
): void {
    $tableName = "adopted_content_{$tableKey}";
    config()->set("content.tables.{$tableKey}", $tableName);
    Schema::create($tableName, function (Blueprint $table): void {
        $table->string('sentinel');
    });
    $migration = require __DIR__."/../../database/migrations/{$migrationFile}";

    expect(fn () => $migration->up())->toThrow(LogicException::class)
        ->and(Schema::hasTable($tableName))->toBeTrue()
        ->and(Schema::hasColumn($tableName, 'sentinel'))->toBeTrue();
})->with([
    'definitions' => [
        'definitions',
        '2026_07_28_100001_create_content_definitions_table.php',
    ],
    'blocks' => [
        'blocks',
        '2026_07_28_100002_create_content_blocks_table.php',
    ],
    'translations' => [
        'blocks_i18n',
        '2026_07_28_100003_create_content_blocks_i18n_table.php',
    ],
    'placements' => [
        'placements',
        '2026_07_28_100004_create_content_placements_table.php',
    ],
    'revisions' => [
        'revisions',
        '2026_07_28_100005_create_content_revisions_table.php',
    ],
]);
