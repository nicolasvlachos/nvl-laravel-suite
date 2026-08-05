<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Content\Casts\ContentCompositionSnapshotCast;
use Nvl\Content\Casts\ContentSchemaCast;
use Nvl\Content\Contracts\ContentDefinitionMigration;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\ContentDefinitionMigrationContextData;
use Nvl\Content\Data\ContentDefinitionMigrationValuesData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\FieldPresets\ConfiguredContentFieldPreset;
use Nvl\Content\FieldTypes\MediaFieldTypeAdapter;
use Nvl\Content\FieldTypes\ReferenceFieldTypeAdapter;
use Nvl\Content\FieldTypes\StringFieldTypeAdapter;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Content\Services\ContentDefinitionLoader;
use Nvl\Content\Services\ContentDefinitionMigrationRegistry;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentFieldTypeRegistry;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Services\ContentReferenceRegistry;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Support\ContentRouteConfiguration;
use Nvl\Content\Tests\Fixtures\HeroV1ToV2ContentMigration;
use Nvl\Content\Tests\Fixtures\TestContentOwner;
use Nvl\Content\Validation\ContentFieldSettingsValidator;
use Nvl\Content\Validation\ContentValidationContext;
use Nvl\Content\Validation\ContentValueValidator;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;

it('round trips typed composition snapshots through the Eloquent cast', function (): void {
    $model = new class extends Model {};
    $cast = new ContentCompositionSnapshotCast;
    $snapshot = new ContentCompositionSnapshotData(
        ownerType: 'page',
        ownerId: 'page-1',
        group: 'main',
        blocks: [],
        version: hash('sha256', 'empty-composition'),
    );

    $encoded = $cast->set($model, 'snapshot', $snapshot, []);
    $decoded = $cast->get($model, 'snapshot', $encoded['snapshot'], []);
    $fromArray = $cast->set($model, 'snapshot', $snapshot->toArray(), []);

    expect($decoded)->toBeInstanceOf(ContentCompositionSnapshotData::class)
        ->and($decoded?->ownerType)->toBe('page')
        ->and($fromArray)->toBe($encoded)
        ->and($cast->get($model, 'snapshot', null, []))->toBeNull()
        ->and($cast->set($model, 'snapshot', null, []))->toBe(['snapshot' => null]);

    expect(fn () => $cast->get($model, 'snapshot', 42, []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $cast->set($model, 'snapshot', 'invalid', []))
        ->toThrow(InvalidArgumentException::class);
});

it('round trips schemas through the Eloquent cast and rejects invalid values', function (): void {
    $model = new class extends Model {};
    $cast = new ContentSchemaCast;
    $schema = new ContentSchema([
        new ContentFieldDefinition('title', 'text', 'Title'),
    ]);
    $encoded = $cast->set($model, 'schema', $schema, []);

    expect($cast->get($model, 'schema', $encoded['schema'], [])->toArray())
        ->toBe($schema->toArray())
        ->and($cast->set($model, 'schema', $schema->toArray(), []))->toBe($encoded);

    expect(fn () => $cast->get($model, 'schema', false, []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $cast->set($model, 'schema', false, []))
        ->toThrow(InvalidArgumentException::class);
});

it('fails closed for malformed package configuration and portable identities', function (): void {
    $checks = [
        function (): void {
            config()->set('content.connection', []);
            ContentConfiguration::connection();
        },
        function (): void {
            config()->set('content.tables.blocks', 'unsafe-table');
            ContentConfiguration::table('blocks');
        },
        function (): void {
            config()->set('content.validation.maximum_items', 0);
            ContentConfiguration::positiveInteger('content.validation.maximum_items', 1);
        },
        function (): void {
            config()->set('content.locales.available', ['en', '']);
            ContentConfiguration::stringList('content.locales.available');
        },
        function (): void {
            config()->set('content.routes.management.prefix', '../admin');
            ContentRouteConfiguration::path('management');
        },
        function (): void {
            config()->set('content.routes.management.name', '');
            ContentRouteConfiguration::name('management');
        },
        function (): void {
            config()->set('content.routes.management.middleware', ['api', '']);
            ContentRouteConfiguration::middleware('management');
        },
        fn () => (new ContentIdentityGuard)->blockKey('invalid key'),
        fn () => (new ContentIdentityGuard)->owner('Page', 'owner-1'),
        fn () => (new ContentIdentityGuard)->owner('page', 'invalid owner'),
        fn () => (new ContentIdentityGuard)->placementKey('invalid key'),
        fn () => (new ContentIdentityGuard)->region('Invalid'),
        fn () => (new ContentIdentityGuard)->group('Invalid'),
        fn () => (new ContentIdentityGuard)->sortOrder(-1),
    ];

    foreach ($checks as $check) {
        expect($check)->toThrow(InvalidArgumentException::class);
    }
});

it('rejects an authorization implementation that violates the package contract', function (): void {
    config()->set('content.authorization.class', stdClass::class);

    expect(fn () => (new ContentServiceProvider(app()))->register())
        ->toThrow(InvalidArgumentException::class);
});

it('validates recursive schema authoring boundaries', function (): void {
    $invalidFields = [
        ['key' => 'invalid key', 'type' => 'text', 'label' => 'Title'],
        ['key' => 'title', 'type' => 'Invalid', 'label' => 'Title'],
        ['key' => 'title', 'type' => 'text', 'preset' => 'Invalid', 'label' => 'Title'],
        ['key' => 'title', 'type' => 'text', 'label' => ''],
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => 'yes'],
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'fields' => 'invalid'],
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'fields' => [false]],
        ['key' => 'items', 'type' => 'list', 'label' => 'Items', 'item' => 'invalid'],
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'settings' => 'invalid'],
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'unknown' => true],
    ];

    foreach ($invalidFields as $field) {
        expect(fn () => ContentFieldDefinition::fromArray($field))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => new ContentFieldDefinition(
        'group',
        'object',
        'Group',
        fields: [
            new ContentFieldDefinition('title', 'text', 'Title'),
            new ContentFieldDefinition('title', 'text', 'Title again'),
        ],
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => ContentSchema::fromArray(['unknown' => []]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => ContentSchema::fromArray(['fields' => 'invalid']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => ContentSchema::fromArray(['fields' => [false]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ContentSchema([
        new ContentFieldDefinition('title', 'text', 'Title'),
        new ContentFieldDefinition('title', 'text', 'Title again'),
    ]))->toThrow(InvalidArgumentException::class);

    $schema = ContentSchema::fromArray(['fields' => [[
        'key' => 'items',
        'type' => 'list',
        'label' => 'Items',
        'item' => ['type' => 'text'],
    ]]]);

    expect($schema->get('items'))->not->toBeNull()
        ->and($schema->get('missing'))->toBeNull()
        ->and($schema->fieldCount())->toBe(2)
        ->and($schema->fieldTypes())->toBe(['items' => 'list'])
        ->and($schema->jsonSerialize())->toBe($schema->toArray());
});

it('enforces deterministic field and migration registries', function (): void {
    $types = new ContentFieldTypeRegistry;
    $types->register(new StringFieldTypeAdapter('text'));

    expect($types->get('text'))->toBeInstanceOf(StringFieldTypeAdapter::class)
        ->and($types->aliases())->toBe(['text']);
    expect(fn () => $types->register(new StringFieldTypeAdapter('text')))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $types->register(new StringFieldTypeAdapter('Invalid')))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $types->get('missing'))
        ->toThrow(InvalidArgumentException::class);

    $presets = new ContentFieldPresetRegistry;
    $valid = new ConfiguredContentFieldPreset(
        'card',
        'Card',
        null,
        ['type' => 'object', 'fields' => []],
    );
    $presets->register($valid);

    expect($presets->get('card'))->toBe($valid)
        ->and($presets->aliases())->toBe(['card'])
        ->and($presets->all())->toBe([$valid]);
    expect(fn () => $presets->register($valid))->toThrow(InvalidArgumentException::class);
    expect(fn () => $presets->register(new ConfiguredContentFieldPreset(
        'Invalid',
        'Invalid',
        null,
        ['type' => 'object'],
    )))->toThrow(InvalidArgumentException::class);
    expect(fn () => $presets->register(new ConfiguredContentFieldPreset(
        'empty-name',
        '',
        null,
        ['type' => 'object'],
    )))->toThrow(InvalidArgumentException::class);
    expect(fn () => $presets->register(new ConfiguredContentFieldPreset(
        'large-description',
        'Large description',
        str_repeat('x', 65_001),
        ['type' => 'object'],
    )))->toThrow(InvalidArgumentException::class);
    expect(fn () => $presets->get('missing'))->toThrow(InvalidArgumentException::class);

    $migrations = new ContentDefinitionMigrationRegistry;
    $migrations->register(new HeroV1ToV2ContentMigration);

    expect($migrations->hasPath('hero', 1, 2))->toBeTrue()
        ->and($migrations->hasPath('hero', 2, 1))->toBeFalse()
        ->and($migrations->path('hero', 1, 2))->toHaveCount(1)
        ->and($migrations->identifiers())->toBe(['hero:1->2']);
    expect(fn () => $migrations->register(new HeroV1ToV2ContentMigration))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $migrations->path('hero', 0, 2))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $migrations->path('hero', 2, 3))
        ->toThrow(InvalidArgumentException::class);

    $invalidMigration = new class implements ContentDefinitionMigration
    {
        public function definitionKey(): string
        {
            return 'Invalid';
        }

        public function fromVersion(): int
        {
            return 1;
        }

        public function toVersion(): int
        {
            return 3;
        }

        public function migrate(
            ContentDefinitionMigrationContextData $context,
        ): ContentDefinitionMigrationValuesData {
            return new ContentDefinitionMigrationValuesData(
                $context->values,
                $context->translations,
                $context->metadata,
            );
        }
    };

    expect(fn () => (new ContentDefinitionMigrationRegistry)->register($invalidMigration))
        ->toThrow(InvalidArgumentException::class);
});

it('normalizes every supported semantic string boundary', function (): void {
    $context = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'field',
        ContentVisibility::Private,
    );
    $valid = [
        ['text', 'Hello', []],
        ['email', 'person@example.com', []],
        ['url', 'https://example.com/path', []],
        ['uri', '/relative/path', []],
        ['uri', 'https://example.com/path', []],
        ['uri', 'mailto:person@example.com', []],
        ['uri', 'tel:+359881234567', []],
        ['color', '#12abEF', []],
        ['date', '2026-08-02', []],
        ['date_time', '2026-08-02T12:30:00+00:00', []],
        ['select', 'published', ['options' => ['published' => 'Published']]],
        ['text', 'ABC-123', ['pattern' => '/^[A-Z]+-[0-9]+$/']],
    ];

    foreach ($valid as [$type, $value, $settings]) {
        $field = new ContentFieldDefinition('field', $type, 'Field', settings: $settings);

        expect((new StringFieldTypeAdapter($type))->normalize($value, $field, $context))
            ->toBe($value);
    }

    expect((new StringFieldTypeAdapter('text'))->normalize(
        null,
        new ContentFieldDefinition('field', 'text', 'Field'),
        $context,
    ))->toBeNull();

    $invalid = [
        ['text', 42, []],
        ['text', 'too long', ['max_length' => 3]],
        ['text', 'x', ['min_length' => 2]],
        ['email', 'invalid', []],
        ['url', '/relative', []],
        ['url', 'https://user:secret@example.com', []],
        ['url', 'http://example.com', []],
        ['uri', '', []],
        ['uri', '//example.com', []],
        ['uri', 'https://user@example.com', []],
        ['uri', 'mailto:invalid', []],
        ['uri', 'tel:xx', []],
        ['color', '#12', []],
        ['date', '2026-02-30', []],
        ['date_time', 'not-a-date', []],
        ['select', 'draft', ['options' => ['published']]],
        ['text', 'lowercase', ['pattern' => '/^[A-Z]+$/']],
    ];

    foreach ($invalid as [$type, $value, $settings]) {
        $field = new ContentFieldDefinition('field', $type, 'Field', settings: $settings);

        expect(fn () => (new StringFieldTypeAdapter($type))->normalize(
            $value,
            $field,
            $context,
        ))->toThrow(InvalidArgumentException::class);
    }
});

it('enforces reference adapter shape, existence, and safe rendering', function (): void {
    $registry = app(ContentReferenceRegistry::class);
    $single = new ReferenceFieldTypeAdapter(false, $registry);
    $multiple = new ReferenceFieldTypeAdapter(true, $registry);
    $field = new ContentFieldDefinition(
        'article',
        'reference',
        'Article',
        settings: ['reference_type' => 'article'],
    );
    $context = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'article',
        ContentVisibility::Private,
    );
    $offline = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'article',
        ContentVisibility::Private,
        resolveExternal: false,
    );

    $single->validateDefinition($field);

    expect($single->normalize(null, $field, $context))->toBeNull()
        ->and($single->normalize('article-1', $field, $context))->toBe('article-1')
        ->and($multiple->normalize(['article-1', 'article-1'], $field, $offline))
        ->toBe(['article-1'])
        ->and($single->render(null, $field, $context))->toBeNull()
        ->and($single->render('article-1', $field, $context))->toMatchArray([
            'id' => 'article-1',
            'title' => 'Article (en)',
        ]);

    expect(fn () => $single->validateDefinition(new ContentFieldDefinition(
        'article',
        'reference',
        'Article',
    )))->toThrow(InvalidArgumentException::class);
    expect(fn () => $single->normalize('article-1', new ContentFieldDefinition(
        'article',
        'reference',
        'Article',
    ), $context))->toThrow(InvalidArgumentException::class);
    expect(fn () => $multiple->normalize('article-1', $field, $context))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $multiple->normalize(
        ['one', 'two'],
        new ContentFieldDefinition(
            'article',
            'reference_list',
            'Articles',
            settings: ['reference_type' => 'article', 'max_items' => 1],
        ),
        $offline,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $single->normalize("invalid\nidentifier", $field, $offline))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $single->normalize('missing', $field, $context))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects inconsistent built-in field settings at the schema boundary', function (): void {
    $validator = app(ContentFieldSettingsValidator::class);
    $invalid = [
        new ContentFieldDefinition('title', 'text', 'Title', settings: ['unknown' => true]),
        new ContentFieldDefinition('title', 'text', 'Title', settings: ['min_length' => 4, 'max_length' => 2]),
        new ContentFieldDefinition('items', 'list', 'Items', settings: ['min_items' => 2, 'max_items' => 1]),
        new ContentFieldDefinition('amount', 'number', 'Amount', settings: ['min' => INF]),
        new ContentFieldDefinition('amount', 'integer', 'Amount', settings: ['min' => 5, 'max' => 2]),
        new ContentFieldDefinition('status', 'select', 'Status', settings: ['options' => []]),
        new ContentFieldDefinition('status', 'multi_select', 'Status', settings: ['options' => ['']]),
        new ContentFieldDefinition('title', 'text', 'Title', settings: ['pattern' => '[invalid']),
        new ContentFieldDefinition('href', 'uri', 'Link', settings: ['allowed_schemes' => []]),
        new ContentFieldDefinition('asset', 'media', 'Asset', settings: ['mime_types' => 'image/jpeg']),
        new ContentFieldDefinition('asset', 'media_collection', 'Assets', settings: ['mime_types' => ['invalid']]),
        new ContentFieldDefinition('article', 'reference', 'Article', settings: ['reference_type' => 'Invalid']),
    ];

    foreach ($invalid as $field) {
        expect(fn () => $validator->validate($field))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => $validator->validate(
        new ContentFieldDefinition('custom', 'consumer_field', 'Custom'),
    ))->not->toThrow(InvalidArgumentException::class);
});

it('enforces media shape availability visibility MIME and authorization boundaries', function (): void {
    $authorization = app(MediaAuthorization::class);
    $single = new MediaFieldTypeAdapter(false, $authorization);
    $multiple = new MediaFieldTypeAdapter(true, $authorization);
    $field = new ContentFieldDefinition(
        'asset',
        'media',
        'Asset',
        settings: ['mime_types' => ['image/jpeg']],
    );
    $privateContext = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'asset',
        ContentVisibility::Private,
    );
    $publicContext = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'asset',
        ContentVisibility::Public,
    );
    $offlineContext = new ContentValidationContext(
        ContentActorData::system(),
        'en',
        'asset',
        ContentVisibility::Private,
        resolveExternal: false,
    );
    $public = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
    $private = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
    ]);
    $unavailable = Media::factory()->create([
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::PendingUpload,
    ]);

    expect($single->normalize(null, $field, $privateContext))->toBeNull()
        ->and($single->render(null, $field, $privateContext))->toBeNull()
        ->and($single->normalize($public->id, $field, $privateContext))->toBe($public->id)
        ->and($multiple->normalize(
            [$public->id, $public->id],
            new ContentFieldDefinition('assets', 'media_collection', 'Assets'),
            $offlineContext,
        ))->toBe([$public->id])
        ->and($multiple->render('invalid', $field, $privateContext))->toBeNull();

    $invalidCalls = [
        fn () => $multiple->normalize($public->id, $field, $offlineContext),
        fn () => $multiple->normalize(
            [$public->id, $private->id],
            new ContentFieldDefinition(
                'assets',
                'media_collection',
                'Assets',
                settings: ['max_items' => 1],
            ),
            $offlineContext,
        ),
        fn () => $multiple->normalize(['invalid'], $field, $offlineContext),
        fn () => $single->normalize($unavailable->id, $field, $privateContext),
        fn () => $single->normalize($private->id, $field, $publicContext),
        fn () => $single->normalize(
            $public->id,
            new ContentFieldDefinition('asset', 'media', 'Asset', settings: ['mime_types' => 'image/jpeg']),
            $privateContext,
        ),
        fn () => $single->normalize(
            $public->id,
            new ContentFieldDefinition('asset', 'media', 'Asset', settings: ['mime_types' => [false]]),
            $privateContext,
        ),
        fn () => $single->normalize(
            $public->id,
            new ContentFieldDefinition('asset', 'media', 'Asset', settings: ['mime_types' => ['image/png']]),
            $privateContext,
        ),
    ];

    config()->set('content.media.allow_private_for_private_blocks', false);
    $invalidCalls[] = fn () => $single->normalize($private->id, $field, $privateContext);

    foreach ($invalidCalls as $call) {
        expect($call)->toThrow(InvalidArgumentException::class);
    }

    config()->set('content.media.allow_private_for_private_blocks', true);
    $denyingAuthorization = new class implements MediaAuthorization
    {
        public function allows(
            MediaActorData $actor,
            MediaAbility $ability,
            ?Media $media = null,
            ?Model $owner = null,
        ): bool {
            return false;
        }
    };
    $denying = new MediaFieldTypeAdapter(false, $denyingAuthorization);

    expect(fn () => $denying->normalize($public->id, $field, $privateContext))
        ->toThrow(InvalidArgumentException::class)
        ->and($denying->render($private->id, $field, $privateContext))->toBeNull();

    $rendered = $multiple->render(
        [$public->id, 'invalid', (string) Str::uuid(), $unavailable->id],
        $field,
        $privateContext,
    );

    expect($rendered)->toBeArray()->toHaveCount(1);
});

it('fails closed for invalid owner registrations identities and group declarations', function (): void {
    $originalMorphMap = Relation::morphMap();
    $registry = new ContentOwnerRegistry(app(ContentIdentityGuard::class));
    $plainModel = new class extends Model {};
    $validOwner = new class extends Model implements ContentOwner
    {
        /** @return list<string> */
        public function contentGroups(): array
        {
            return ['main'];
        }

        public function contentPlacements(): MorphMany
        {
            return $this->morphMany(ContentPlacement::class, 'owner');
        }
    };
    $emptyGroups = new class extends Model implements ContentOwner
    {
        /** @return list<string> */
        public function contentGroups(): array
        {
            return [];
        }

        public function contentPlacements(): MorphMany
        {
            return $this->morphMany(ContentPlacement::class, 'owner');
        }
    };
    $duplicateGroups = new class extends Model implements ContentOwner
    {
        /** @return list<string> */
        public function contentGroups(): array
        {
            return ['main', 'main'];
        }

        public function contentPlacements(): MorphMany
        {
            return $this->morphMany(ContentPlacement::class, 'owner');
        }
    };

    try {
        expect(fn () => $registry->register('Invalid', $validOwner::class))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->register('plain', $plainModel::class))
            ->toThrow(InvalidArgumentException::class);

        $registry->register('boundary-owner', $validOwner::class);

        expect($registry->aliases())->toBe(['boundary-owner'])
            ->and($registry->registered('boundary-owner'))->toBe($validOwner::class)
            ->and($registry->registered('missing'))->toBeNull()
            ->and($registry->model('boundary-owner'))->toBe($validOwner::class)
            ->and($registry->groups($validOwner))->toBe(['main']);
        expect(fn () => $registry->register('boundary-owner', $validOwner::class))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->resolve('missing', (string) Str::uuid()))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->model('missing'))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->type($emptyGroups))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->id($validOwner))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->groups($emptyGroups))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->groups($duplicateGroups))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $registry->assertGroup($validOwner, 'secondary'))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => (new ContentOwnerRegistry(app(ContentIdentityGuard::class)))
            ->register('alternate-boundary-owner', TestContentOwner::class))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        Relation::morphMap($originalMorphMap, merge: false);
    }
});

it('validates required and localized nested content structures', function (): void {
    $validator = app(ContentValueValidator::class);
    $actor = ContentActorData::system();
    $required = ContentSchema::fromArray([[
        'key' => 'title',
        'type' => 'text',
        'label' => 'Title',
        'required' => true,
    ]]);

    expect(fn () => $validator->validate(
        $required,
        [],
        [],
        $actor,
        ContentVisibility::Private,
        publishing: true,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $validator->validate(
        $required,
        ['title' => '   '],
        [],
        $actor,
        ContentVisibility::Private,
        publishing: true,
    ))->toThrow(InvalidArgumentException::class);

    $object = ContentSchema::fromArray([[
        'key' => 'copy',
        'type' => 'object',
        'label' => 'Copy',
        'fields' => [[
            'key' => 'heading',
            'type' => 'text',
            'label' => 'Heading',
            'required' => true,
        ]],
    ]]);

    expect(fn () => $validator->validate(
        $object,
        ['copy' => ['unknown' => true]],
        [],
        $actor,
        ContentVisibility::Private,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $validator->validate(
        $object,
        ['copy' => []],
        [],
        $actor,
        ContentVisibility::Private,
        publishing: true,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $validator->validate(
        $object,
        ['copy' => ['heading' => '']],
        [],
        $actor,
        ContentVisibility::Private,
        publishing: true,
    ))->toThrow(InvalidArgumentException::class);

    $list = ContentSchema::fromArray([[
        'key' => 'items',
        'type' => 'list',
        'label' => 'Items',
        'item' => ['type' => 'text'],
    ]]);
    $validated = $validator->validate(
        $list,
        ['items' => ['one', 'two']],
        [],
        $actor,
        ContentVisibility::Private,
    );

    expect($validator->render(
        $list,
        $validated->values,
        $actor,
        'en',
        ContentVisibility::Private,
    ))->toBe(['items' => ['one', 'two']]);

    $localizedList = ContentSchema::fromArray([[
        'key' => 'items',
        'type' => 'list',
        'label' => 'Items',
        'item' => ['type' => 'text', 'localized' => true, 'required' => true],
    ]]);

    expect(fn () => $validator->validate(
        $localizedList,
        ['items' => ['base']],
        ['bg' => ['items' => ['one', 'two']]],
        $actor,
        ContentVisibility::Private,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $validator->validate(
        $localizedList,
        ['items' => ['base']],
        ['en' => ['items' => []]],
        $actor,
        ContentVisibility::Private,
        publishing: true,
    ))->toThrow(InvalidArgumentException::class);

    $localizedTable = ContentSchema::fromArray([[
        'key' => 'rows',
        'type' => 'table',
        'label' => 'Rows',
        'fields' => [
            ['key' => 'slug', 'type' => 'text', 'label' => 'Slug'],
            ['key' => 'label', 'type' => 'text', 'label' => 'Label', 'localized' => true],
        ],
    ]]);

    expect(fn () => $validator->validate(
        $localizedTable,
        ['rows' => [['slug' => 'one']]],
        ['bg' => ['rows' => [['label' => 'Едно'], ['label' => 'Две']]]],
        $actor,
        ContentVisibility::Private,
    ))->toThrow(InvalidArgumentException::class);

    $validator->assertDefaults(ContentSchema::fromArray([[
        'key' => 'items',
        'type' => 'list',
        'label' => 'Items',
        'item' => ['type' => 'text', 'default' => 'default item'],
    ]]));
});

it('loads deterministic PHP and JSON definitions and rejects unsafe discovery inputs', function (): void {
    $temporary = sys_get_temp_dir().'/nvl-content-boundaries-'.Str::uuid();
    $definitions = $temporary.'/definitions';
    File::ensureDirectoryExists($definitions);

    try {
        File::put($definitions.'/one.content.php', <<<'PHP'
<?php

return [
    'php-card' => [
        'name' => 'PHP card',
        'schema' => ['fields' => []],
    ],
];
PHP);
        File::put($definitions.'/two.content.json', json_encode([
            'json-card' => [
                'name' => 'JSON card',
                'schema' => ['fields' => []],
            ],
        ], JSON_THROW_ON_ERROR));
        config()->set([
            'content.definitions' => [],
            'content.definition_paths' => [$definitions],
            'content.required_definition_paths' => [],
            'content.allowed_definition_roots' => [$temporary],
        ]);

        expect(array_map(
            static fn ($definition): string => $definition->key,
            app(ContentDefinitionLoader::class)->load(),
        ))->toBe(['json-card', 'php-card']);

        config()->set('content.definitions', 'invalid');
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definitions' => [],
            'content.definition_paths' => 'invalid',
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definition_paths' => [],
            'content.required_definition_paths' => [$temporary.'/missing'],
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.required_definition_paths' => [],
            'content.definition_paths' => [$definitions],
            'content.allowed_definition_roots' => [],
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.allowed_definition_roots' => [$temporary],
            'content.definition_limits.maximum_files' => 1,
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definitions' => [
                'key' => 'single-definition',
                'name' => 'Single definition',
                'schema' => ['fields' => []],
            ],
            'content.definition_paths' => [],
            'content.required_definition_paths' => [],
            'content.allowed_definition_roots' => [$temporary],
            'content.definition_limits.maximum_files' => 500,
        ]);
        expect(app(ContentDefinitionLoader::class)->load()[0]->key)
            ->toBe('single-definition');

        $invalidDefinitions = [
            [2 => ['name' => 'Numeric keyed', 'schema' => ['fields' => []]]],
            [['name' => 'Missing key', 'schema' => ['fields' => []]]],
            [
                ['key' => 'duplicate', 'name' => 'Duplicate', 'schema' => ['fields' => []]],
                ['key' => 'duplicate', 'name' => 'Duplicate', 'schema' => ['fields' => []]],
            ],
            ['outer' => ['key' => 'inner', 'name' => 'Conflict', 'schema' => ['fields' => []]]],
            ['invalid-types' => ['name' => false, 'schema' => ['fields' => []]]],
            ['invalid-scopes' => [
                'name' => 'Invalid scopes',
                'schema' => ['fields' => []],
                'allowed_scopes' => ['scope' => 'site'],
            ]],
            ['invalid-scope-item' => [
                'name' => 'Invalid scope item',
                'schema' => ['fields' => []],
                'allowed_scopes' => [false],
            ]],
            ['conflicting-properties' => [
                'name' => 'Conflicting properties',
                'schema' => ['fields' => []],
                'allowed_scopes' => ['site'],
                'allowedScopes' => ['global'],
            ]],
        ];

        foreach ($invalidDefinitions as $invalidDefinitionsConfiguration) {
            config()->set('content.definitions', $invalidDefinitionsConfiguration);
            expect(fn () => app(ContentDefinitionLoader::class)->load())
                ->toThrow(InvalidArgumentException::class);
        }

        config()->set([
            'content.definitions' => [],
            'content.definition_paths' => [],
            'content.required_definition_paths' => 'invalid',
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.required_definition_paths' => [],
            'content.definition_paths' => [''],
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definition_paths' => [$temporary],
            'content.allowed_definition_roots' => [$definitions],
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definition_paths' => [],
            'content.allowed_definition_roots' => [$temporary.'/missing-root'],
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        $singleFile = $definitions.'/single.content.json';
        File::put($singleFile, json_encode([
            'file-definition' => [
                'name' => 'File definition',
                'schema' => ['fields' => []],
            ],
        ], JSON_THROW_ON_ERROR));
        config()->set([
            'content.definition_paths' => [$singleFile],
            'content.allowed_definition_roots' => [$temporary],
            'content.definition_limits.maximum_file_bytes' => 1_048_576,
        ]);
        expect(app(ContentDefinitionLoader::class)->load()[0]->key)
            ->toBe('file-definition');

        $invalidFile = $definitions.'/invalid.content.php';
        File::put($invalidFile, '<?php return "invalid";');
        config()->set('content.definition_paths', [$invalidFile]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);

        config()->set([
            'content.definition_paths' => [$singleFile],
            'content.definition_limits.maximum_file_bytes' => 1,
        ]);
        expect(fn () => app(ContentDefinitionLoader::class)->load())
            ->toThrow(InvalidArgumentException::class);
    } finally {
        File::deleteDirectory($temporary);
    }
});

it('validates command input before changing definition state', function (): void {
    expect(fn () => $this->artisan('nvl:content:definitions:sync', [
        '--format' => 'yaml',
    ])->run())->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->artisan('nvl:content:definitions:migrate', [
        '--format' => 'yaml',
    ])->run())->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->artisan('nvl:content:definitions:migrate', [
        '--definition' => '',
    ])->run())->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->artisan('nvl:content:definitions:migrate', [
        '--limit' => 'zero',
    ])->run())->toThrow(InvalidArgumentException::class);

    $this->artisan('nvl:content:definitions:sync', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('create: hero');
    $this->artisan('nvl:content:definitions:sync')
        ->assertSuccessful()
        ->expectsOutputToContain('create: hero');
    $this->artisan('nvl:content:definitions:migrate', ['--dry-run' => true])
        ->assertSuccessful();

    expect(fn () => $this->artisan('nvl:content:doctor', [
        '--format' => 'yaml',
    ])->run())->toThrow(InvalidArgumentException::class);

    config()->set('content.connection', []);
    $this->artisan('nvl:content:doctor')
        ->assertSuccessful()
        ->expectsOutputToContain('database.error')
        ->expectsOutputToContain('media.connection_error');
});
