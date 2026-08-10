<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Workbench\Models\IntegrationTestModel;

it('gathers package type sources and translatable resources through central registries', function (): void {
    $catalog = require base_path('tools/package-family.php');
    $expectedTypeSourcePackages = $catalog['typescript_sources'];
    $typeSourcePackages = array_values(array_filter(array_map(
        static fn (array $source): ?string => is_string($source['package'])
            ? substr($source['package'], 4)
            : null,
        app(TypeScriptSourceRegistry::class)->descriptors(),
    )));
    $translationResources = app(TranslationResourceRegistry::class)->keys();
    sort($expectedTypeSourcePackages);
    sort($typeSourcePackages);
    sort($translationResources);

    expect($typeSourcePackages)
        ->toBe($expectedTypeSourcePackages)
        ->and(base_path('resources/js/types/generated/mail-notifications.d.ts'))->toBeFile()
        ->and(File::get(base_path('resources/js/types/generated/mail-notifications.d.ts')))
        ->toContain('MailDeliveryStatus')
        ->and($translationResources)->toBe([
            'content.blocks',
            'forms.forms',
            'media.assets',
            'metafields.definitions',
            'metafields.values',
            'pages.pages',
            'seo.profiles',
            'taxonomy.terms',
            'templates.templates',
        ]);
});

it('uses one stable owner alias across dependent package registries', function (): void {
    $model = IntegrationTestModel::create(['name' => 'Registered reference owner']);

    expect(app(ContentOwnerRegistry::class)->type($model))
        ->toBe('reference_models')
        ->and(app(ContentOwnerRegistry::class)->groups($model))->toBe(['main'])
        ->and(app(MetafieldOwnerRegistry::class)->resolveOwnerType($model))
        ->toBe('reference_models')
        ->and(app(SeoOwnerRegistry::class)->aliasFor($model))
        ->toBe('reference_models')
        ->and(app(TaxonomyOwnerRegistry::class)->aliasFor($model))
        ->toBe('reference_models')
        ->and($model->getMorphClass())->toBe('reference_models');
});

it('can combine taxonomy, metafields, and activity on a single model', function () {
    Storage::fake('local');

    $category = app(CreateTermAction::class)->execute(
        new MutateTermPayload(
            taxonomy: 'category',
            slug: 'electronics',
            translations: [
                'en' => ['name' => 'Electronics'],
            ],
        ),
    );

    $model = IntegrationTestModel::create([
        'name' => 'Super Gadget',
        'category_id' => $category->id,
    ]);
    app(AttachTermsAction::class)->execute($model, 'category', [$category]);

    expect($model->category_id)->toBe($category->id)
        ->and($model->categories()->sole()->is($category))->toBeTrue();

    $definitionAction = app(CreateMetafieldDefinitionAction::class);
    $setAction = app(SetMetafieldAction::class);
    $listAction = app(ListOwnerMetafieldsAction::class);

    $assignmentPayload = [
        'ownerType' => 'reference_models',
        'section' => 'general',
        'isRequired' => false,
        'isActive' => true,
    ];

    $definitionAction->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'specs',
            'key' => 'color',
            'type' => MetafieldTypeEnum::String,
            'assignment' => $assignmentPayload,
            'translations' => [
                'en' => ['title' => 'Color'],
            ],
        ])
    );

    $definitionAction->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'specs',
            'key' => 'weight',
            'type' => MetafieldTypeEnum::Integer,
            'assignment' => $assignmentPayload,
            'translations' => [
                'en' => ['title' => 'Weight'],
            ],
        ])
    );

    $setAction->execute($model, 'specs.color', 'red');
    $setAction->execute($model, 'specs.weight', 1);

    $metafields = $listAction->execute($model);

    expect($metafields)->toHaveCount(2)
        ->and($metafields[0]->value)->toBe('red')
        ->and($metafields[1]->value)->toBe(1);

    activity()
        ->performedOn($model)
        ->event('created_and_configured')
        ->log('System configured the gadget');

    $activity = ActivityLog::where('subject_type', $model->getMorphClass())
        ->where('subject_id', $model->id)
        ->where('event', 'created_and_configured')
        ->first();

    expect($activity)->not->toBeNull()
        ->and(Str::isUuid((string) $activity->id))->toBeTrue()
        ->and($activity->event)->toBe('created_and_configured');
});

it('can attach media to the integration model using isolated package actions', function () {
    Storage::fake('local');

    $model = IntegrationTestModel::create([
        'name' => 'Media Gadget',
    ]);

    $file = UploadedFile::fake()->image('gadget.jpg', 600, 600);

    $uploadAction = app(UploadMediaAction::class);
    $attachAction = app(AttachMediaAction::class);

    $media = $uploadAction->execute(
        file: $file,
        disk: 'local',
        model: $model,
        slot: new MediaSlot('default'),
        fileName: 'gadget.jpg',
        isPublic: true,
        tags: []
    );

    $association = $attachAction->execute(
        media: $media,
        model: $model,
        collection: 'default',
        locale: null,
        order: null
    );

    expect($model->media()->count())->toBe(1)
        ->and($model->media()->first()->filename)->toBe('gadget.jpg');
});

it('keeps eager-loaded reference owner reads within a constant query budget', function (): void {
    foreach (range(1, 25) as $index) {
        IntegrationTestModel::create(['name' => "Reference owner {$index}"]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $models = IntegrationTestModel::query()
        ->with([
            'categories',
            'comments',
            'contentPlacements',
            'media',
            'metafields',
            'seoProfiles',
        ])
        ->get();

    $queryCount = count(DB::getQueryLog());

    foreach ($models as $model) {
        $model->categories->count();
        $model->comments->count();
        $model->contentPlacements->count();
        $model->media->count();
        $model->metafields->count();
        $model->seoProfiles->count();
    }

    expect($models)->toHaveCount(25)
        ->and($queryCount)->toBeLessThanOrEqual(7)
        ->and(DB::getQueryLog())->toHaveCount($queryCount);
});

it('passes every available package doctor in strict machine-readable mode', function (string $command): void {
    if ($command === 'nvl:activity:doctor') {
        config()->set('cache.default', 'database');
        config()->set('queue.default', 'database');
        config()->set(
            'queue.connections.database.retry_after',
            PurgeActivityLogsJob::TIMEOUT_SECONDS + 60,
        );
    }

    if ($command === 'nvl:comments:doctor') {
        config()->set('cache.default', 'database');
    }

    $this->artisan($command, [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful();
})->with([
    'activity' => 'nvl:activity:doctor',
    'comments' => 'nvl:comments:doctor',
    'content' => 'nvl:content:doctor',
    'forms' => 'nvl:forms:doctor',
    'mail notifications' => 'nvl:mail-notifications:doctor',
    'media' => 'nvl:media:doctor',
    'metafields' => 'nvl:metafields:doctor',
    'pages' => 'nvl:pages:doctor',
    'seo' => 'nvl:seo:doctor',
    'settings' => 'nvl:settings:doctor',
    'taxonomy' => 'nvl:taxonomy:doctor',
    'templates' => 'nvl:templates:doctor',
    'translatable' => 'nvl:translatable:doctor',
    'translations' => 'nvl:translations:doctor',
]);
