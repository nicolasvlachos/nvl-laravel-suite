<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Actions\UpdateContentBlockAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Enums\ContentMutationMode;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\ContentSnapshotService;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Media\Data\Display\PublicMedia;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Templates\Actions\AssignTemplateAction;
use Nvl\Templates\Actions\CreateTemplateAction;
use Nvl\Templates\Actions\CreateTemplateVersionAction;
use Nvl\Templates\Actions\GetTemplateAction;
use Nvl\Templates\Actions\GetTemplateRenderAction;
use Nvl\Templates\Actions\ListTemplateRendersAction;
use Nvl\Templates\Actions\ListTemplatesAction;
use Nvl\Templates\Actions\ProcessTemplateRenderAction;
use Nvl\Templates\Actions\PublishTemplateVersionAction;
use Nvl\Templates\Actions\QueueTemplateRenderAction;
use Nvl\Templates\Actions\RecoverStaleTemplateRendersAction;
use Nvl\Templates\Actions\RenderStoredTemplateAction;
use Nvl\Templates\Actions\RenderTemplateAction;
use Nvl\Templates\Actions\UnassignTemplateAction;
use Nvl\Templates\Actions\UpdateTemplateAction;
use Nvl\Templates\Actions\UpdateTemplateVersionAction;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\MediaTemplateAssetData;
use Nvl\Templates\Data\Mutations\AssignTemplateData;
use Nvl\Templates\Data\Mutations\CreateTemplateData;
use Nvl\Templates\Data\Mutations\CreateTemplateVersionData;
use Nvl\Templates\Data\Mutations\RenderTemplateData;
use Nvl\Templates\Data\Mutations\UpdateTemplateData;
use Nvl\Templates\Data\Mutations\UpdateTemplateVersionData;
use Nvl\Templates\Data\PdfMargins;
use Nvl\Templates\Data\PdfOptions;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Data\TemplateManagementData;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Data\TemplateRenderData;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Enums\PdfOrientation;
use Nvl\Templates\Enums\PdfPageSize;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Exceptions\TemplateResolutionException;
use Nvl\Templates\Exceptions\TemplatesException;
use Nvl\Templates\Http\Controllers\TemplateRenderController;
use Nvl\Templates\Jobs\RenderTemplateJob;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Providers\TemplatesServiceProvider;
use Nvl\Templates\Services\CanonicalJson;
use Nvl\Templates\Services\ConfiguredTemplatePayloadValidator;
use Nvl\Templates\Services\MediaTemplateAssetRegistry;
use Nvl\Templates\Services\MediaTemplateAssetResolver;
use Nvl\Templates\Services\NullTemplateAssetResolver;
use Nvl\Templates\Services\PdfHtmlGuard;
use Nvl\Templates\Services\PdfTemporaryDirectoryResolver;
use Nvl\Templates\Services\SafeFilesystemPathResolver;
use Nvl\Templates\Services\StoredTemplateRenderResolver;
use Nvl\Templates\Services\TemplateAdoptionManifest;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Services\TemplateResponseFactory;
use Nvl\Templates\Support\PdfConfig\Data\HeaderFooterData;
use Nvl\Templates\Support\PdfConfig\Data\PageNumberingData;
use Nvl\Templates\Support\PdfConfig\EngineConfig;
use Nvl\Templates\Support\TemplateActorFactory;
use Nvl\Templates\Support\TemplatesRouteConfiguration;
use Nvl\Templates\Template as RenderableTemplate;
use Nvl\Templates\Tests\Fixtures\TestClassPdfTemplate;
use Nvl\Templates\Tests\Fixtures\TestTemplateOwner;

/**
 * @param  array<string, mixed>  $values
 * @param  array<string, array<string, mixed>>  $translations
 * @return array{0: TemplateVersion, 1: ContentBlock}
 */
function createComposedTemplateVersion(
    Template $template,
    TemplateActorData $actor,
    array $values,
    array $translations,
): array {
    $version = app(CreateTemplateVersionAction::class)->execute(
        $template,
        new CreateTemplateVersionData,
        $actor,
    );
    $block = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'template-copy',
            key: "template-version-{$version->id}",
            values: $values,
            translations: $translations,
        ),
        ContentActorData::system(),
    );
    app(PlaceContentBlockAction::class)->execute(
        $block,
        $version,
        TemplateVersion::CONTENT_GROUP,
        new PlaceContentBlockData(
            key: 'body',
            region: 'main',
        ),
        ContentActorData::system(),
    );

    return [$version, $block];
}

beforeEach(function (): void {
    Storage::fake('public');
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

it('preserves the reusable class-template API over the verified PDF pipeline', function (): void {
    $localAssetPath = storage_path('app/template-tests/logo.png');
    File::ensureDirectoryExists(dirname($localAssetPath));
    File::put(
        $localAssetPath,
        (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ),
    );
    $template = app(TestClassPdfTemplate::class)
        ->setLanguage('bg')
        ->withFallbackLanguage('en')
        ->setData(['recipientName' => 'Ada'])
        ->withContent(['heading' => 'Statement'])
        ->setOption('reference', 'REF-100')
        ->addAsset('logo', $localAssetPath)
        ->setHeaderHtml('<p>Verified header</p>');
    $html = $template->render();
    $pdf = $template->generate();
    $download = $pdf->download('statement.pdf');
    $path = $template->save('statement.pdf');
    $confinedPath = $template->save('../confined-statement.pdf');
    $storedPath = $template
        ->setOption('storage_disk', 'public')
        ->saveToStorage('../stored-statement.txt');

    try {
        expect($html['html'])->toContain('lang="bg"')
            ->and($html['html'])->toContain('Statement')
            ->and($html['html'])->toContain('Ada')
            ->and($html['html'])->toContain('REF-100')
            ->and($pdf->getContent())->toStartWith('%PDF-')
            ->and($download->headers->get('content-disposition'))
            ->toContain('attachment; filename=statement.pdf')
            ->and($download->headers->get('cache-control'))
            ->toContain('no-store')
            ->and($path)->toEndWith('/template-tests/statement.pdf')
            ->and(File::exists($path))->toBeTrue()
            ->and($confinedPath)->toEndWith('/template-tests/confined-statement.pdf')
            ->and(File::exists($confinedPath))->toBeTrue()
            ->and($storedPath)->toBe('template-tests/stored-statement.txt.pdf');
        Storage::disk('public')->assertExists($storedPath);
    } finally {
        File::delete([$path, $confinedPath, $localAssetPath]);
    }
});

it('fails closed for unsafe class-template assets and PDF diagnostics', function (): void {
    $template = app(TestClassPdfTemplate::class);
    $outsideAssetPath = sys_get_temp_dir().'/nvl-template-outside-'.str()->uuid().'.png';
    File::put(
        $outsideAssetPath,
        (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ),
    );

    try {
        expect(fn () => $template->addUrlAsset(
            'remote',
            'https://untrusted.invalid/image.png',
        ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $template->addAsset('outside', $outsideAssetPath))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        File::delete($outsideAssetPath);
    }

    $template
        ->setData(['recipientName' => 'Ada'])
        ->withContent(['heading' => 'Statement'])
        ->addInlineAsset(
            'logo',
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
    $template->getConfig()->showImageErrors = true;

    expect(fn () => $template->generate())
        ->toThrow(InvalidArgumentException::class);

    $template->getConfig()->showImageErrors = false;
    $template->getConfig()->setTempDir('/outside-allowed-template-root');

    expect(fn () => $template->generate())
        ->toThrow(InvalidArgumentException::class);
});

it('places class-template page numbering in the requested header or footer', function (): void {
    $top = (new EngineConfig)
        ->setHeaderFooter(new HeaderFooterData(
            headerHtml: '<p>Header</p>',
            footerHtml: '<p>Footer</p>',
        ))
        ->setPageNumbering(new PageNumberingData(
            enabled: true,
            position: 'top-right',
        ))
        ->toPdfOptions();
    $bottom = (new EngineConfig)
        ->setPageNumbering(new PageNumberingData(
            enabled: true,
            position: 'bottom-center',
        ))
        ->toPdfOptions();

    expect($top->headerHtml)->toContain('Header', '{PAGENO}/{nbpg}')
        ->and($top->footerHtml)->toBe('<p>Footer</p>')
        ->and($bottom->headerHtml)->toBeNull()
        ->and($bottom->footerHtml)->toContain('{PAGENO}/{nbpg}');
});

it('installs its composition schema with management routes disabled', function (): void {
    expect(Schema::hasTable(TemplatesTables::Templates))->toBeTrue()
        ->and(Schema::hasTable(TemplatesTables::I18n))->toBeTrue()
        ->and(Schema::hasTable(TemplatesTables::Versions))->toBeTrue()
        ->and(Schema::hasColumn(TemplatesTables::Versions, 'content_snapshot'))->toBeTrue()
        ->and(Schema::hasTable(TemplatesTables::Assignments))->toBeTrue()
        ->and(Schema::hasTable(TemplatesTables::Renders))->toBeTrue()
        ->and(Schema::hasTable('template_versions_i18n'))->toBeFalse()
        ->and(Schema::hasTable('template_assets'))->toBeFalse()
        ->and(Route::has('nvl.templates.management.index'))->toBeFalse()
        ->and(Route::has('nvl.templates.render.execute'))->toBeFalse();

    $this->artisan('nvl:templates:sync')->assertSuccessful();
    $inactive = Template::query()->where('key', 'welcome')->firstOrFail();
    $inactive = app(UpdateTemplateAction::class)->execute(
        $inactive,
        new UpdateTemplateData(
            status: TemplateStatus::Inactive,
            expectedRevision: $inactive->revision,
        ),
        TemplateActorData::system(),
    );
    $this->artisan('nvl:templates:sync')->assertSuccessful();
    $orphan = Template::query()->create([
        'key' => 'removed-definition',
        'renderer' => 'test',
        'status' => TemplateStatus::Active,
        'schema' => [],
    ]);
    $this->artisan('nvl:templates:sync', [
        '--dry-run' => true,
        '--format' => 'json',
    ])->assertSuccessful()->expectsOutputToContain('"operation": "archive"');
    expect($orphan->refresh()->status)->toBe(TemplateStatus::Active);
    $this->artisan('nvl:templates:sync')->assertSuccessful();
    expect($orphan->refresh()->status)->toBe(TemplateStatus::Archived)
        ->and($inactive->refresh()->status)->toBe(TemplateStatus::Inactive);

    $publishedMigrationPath = realpath(__DIR__.'/../../database/migrations');
    $publishableMigrationPaths = array_map(
        static fn (mixed $path): string|false => is_string($path) ? realpath($path) : false,
        array_keys(ServiceProvider::pathsToPublish(
            TemplatesServiceProvider::class,
            'templates-migrations',
        )),
    );

    expect($publishedMigrationPath)->toBeString()
        ->and($publishableMigrationPaths)->toContain($publishedMigrationPath);

    $this->artisan('nvl:templates:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"pdf.version"');

    config()->set([
        'templates.rendering.output.persist' => false,
        'templates.rendering.output.disk' => 'missing-disk',
    ]);
    $this->artisan('nvl:templates:doctor', [
        '--scope' => 'database',
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful()->expectsOutputToContain('"output.disk": true');
});

it('fails closed for unowned canonical tables and reports missing named indexes', function (): void {
    $creator = '2026_07_27_100001_create_templates_table';
    $record = DB::table('migrations')->where('migration', $creator)->first();
    expect($record)->not->toBeNull();
    DB::table('migrations')->where('migration', $creator)->delete();
    $preflight = require __DIR__.'/../../database/migrations/2026_07_27_100000_assert_template_schema_compatibility.php';
    $exception = null;

    try {
        $preflight->up();
    } catch (Throwable $throwable) {
        $exception = $throwable;
    } finally {
        DB::table('migrations')->insert((array) $record);
    }

    expect($exception)->toBeInstanceOf(LogicException::class)
        ->and($exception?->getMessage())->toContain('already exists without package creator');

    Schema::table(TemplatesTables::Templates, function (Blueprint $table): void {
        $table->dropIndex('templates_status_updated_idx');
    });

    try {
        $this->artisan('nvl:templates:doctor', [
            '--scope' => 'database',
            '--strict' => true,
            '--format' => 'json',
        ])->assertFailed()
            ->expectsOutputToContain('"indexes.templates.canonical": false');
    } finally {
        Schema::table(TemplatesTables::Templates, function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'templates_status_updated_idx');
        });
    }
});

it('honors configured pagination and loads complete management aggregates', function (): void {
    config()->set('templates.limits.per_page', 1);
    $actor = TemplateActorData::system();
    $welcome = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'pdf-report',
            translations: ['en' => ['title' => 'PDF report']],
        ),
        $actor,
    );
    $page = app(ListTemplatesAction::class)->execute(FilterSet::none(), $actor);
    $aggregate = app(GetTemplateAction::class)->execute($welcome, $actor);

    expect($page->total())->toBe(2)
        ->and($page->perPage())->toBe(1)
        ->and($page->items())->toHaveCount(1)
        ->and($aggregate->relationLoaded('translations'))->toBeTrue()
        ->and($aggregate->relationLoaded('versions'))->toBeTrue()
        ->and($aggregate->relationLoaded('assignments'))->toBeTrue();
});

it('keeps localized template list queries independent of result size', function (): void {
    $actor = TemplateActorData::system();
    $create = static function (int $index): void {
        $template = Template::query()->create([
            'key' => "query-template-{$index}",
            'renderer' => 'test',
            'status' => TemplateStatus::Active,
            'schema' => [],
        ]);
        $template->translations()->create([
            'locale' => 'en',
            'title' => "Query template {$index}",
        ]);
    };
    $measure = static function () use ($actor): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = app(ListTemplatesAction::class)->execute(
            FilterSet::none(),
            $actor,
            100,
        );
        $queryCount = count(DB::getQueryLog());

        foreach ($page->items() as $template) {
            $template->translations->count();
        }

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return $queryCount;
    };

    $create(1);
    $singleQueryCount = $measure();

    foreach (range(2, 25) as $index) {
        $create($index);
    }

    $populatedQueryCount = $measure();

    expect($singleQueryCount)->toBeLessThanOrEqual(3)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

it('renders a directly constructed template through the same core pipeline', function (): void {
    $template = new RenderableTemplate(
        key: 'direct-example',
        view: 'template-tests::core',
        data: ['message' => '<script>alert("unsafe")</script>'],
        options: new TemplateOptions(
            renderer: 'blade',
            locale: 'EN_us',
            subject: 'Direct template',
            filename: 'direct-example.html',
        ),
        schema: [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'maxLength' => 200],
            ],
            'required' => ['message'],
            'additionalProperties' => false,
        ],
        settings: ['tone' => 'formal'],
    );
    $result = app(RenderTemplateAction::class)->execute($template);
    $response = app(TemplateResponseFactory::class)->download($result);
    $defaultView = app(RenderTemplateAction::class)->execute(new RenderableTemplate(
        key: 'default-view',
        data: ['content' => '<strong>Escaped</strong>'],
        options: new TemplateOptions(locale: 'en'),
    ));

    expect($result->renderer)->toBe('blade')
        ->and($result->mimeType)->toBe('text/html; charset=UTF-8')
        ->and($result->suggestedFilename)->toBe('direct-example.html')
        ->and($result->byteSize)->toBe(strlen($result->content))
        ->and($result->checksum)->toBe(hash('sha256', $result->content))
        ->and($result->content)->toContain('lang="en-us"')
        ->and($result->content)->toContain('&lt;script&gt;')
        ->and($result->content)->not->toContain('<script>')
        ->and($result->content)->toContain('formal')
        ->and($response->headers->get('content-type'))
        ->toBe('text/html; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))
        ->toContain('attachment; filename=direct-example.html')
        ->and($response->headers->get('etag'))
        ->toBe('"'.$result->checksum.'"')
        ->and($defaultView->content)->toContain('&lt;strong&gt;Escaped&lt;/strong&gt;')
        ->and($defaultView->content)->not->toContain('<strong>Escaped</strong>');
});

it('publishes the bundled Blade foundations to a guarded custom path', function (): void {
    $root = sys_get_temp_dir().'/nvl-templates-views-'.str()->uuid();
    File::ensureDirectoryExists($root);
    config()->set('templates.views.allowed_publish_roots', [$root]);

    try {
        $this->artisan('nvl:templates:views:publish', [
            '--path' => $root.'/documents',
        ])->assertSuccessful();

        expect(File::exists($root.'/documents/html/document.blade.php'))->toBeTrue()
            ->and(File::exists($root.'/documents/pdf/document.blade.php'))->toBeTrue()
            ->and(File::exists($root.'/documents/components/page-break.blade.php'))
            ->toBeTrue();

        expect(fn () => $this->artisan('nvl:templates:views:publish', [
            '--path' => dirname($root).'/escaped',
        ]))->toThrow(InvalidArgumentException::class);
    } finally {
        File::deleteDirectory($root);
    }
});

it('publishes and queues an immutable localized content composition', function (): void {
    Queue::fake();
    $actor = TemplateActorData::system();
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    [$version, $block] = createComposedTemplateVersion(
        $template,
        $actor,
        [],
        ['en' => ['text' => 'Welcome', 'subject' => 'Hello']],
    );
    $version = app(PublishTemplateVersionAction::class)->execute(
        $version,
        $version->revision,
        $actor,
    );
    $result = app(RenderStoredTemplateAction::class)->execute(
        $template,
        new RenderTemplateData('en', ['name' => 'Ada']),
        $actor,
    );
    $queued = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData('en', ['name' => 'Ada'], idempotencyKey: 'welcome-ada'),
        $actor,
    );
    $same = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData('en', ['name' => 'Ada'], idempotencyKey: 'welcome-ada'),
        $actor,
    );
    app(UpdateContentBlockAction::class)->execute(
        $block,
        new UpdateContentBlockData(
            expectedRevision: $block->revision,
            mode: ContentMutationMode::Patch,
            translations: ['en' => ['text' => 'Changed after publication']],
        ),
        ContentActorData::system(),
    );
    $snapshotResult = app(RenderStoredTemplateAction::class)->execute(
        $template,
        new RenderTemplateData('en', ['name' => 'Ada']),
        $actor,
    );

    expect($version->status)->toBe(TemplateVersionStatus::Published)
        ->and($template->renderer)->toBe('test')
        ->and($template->schema)->toHaveKey('name')
        ->and($version->content_hash)->toHaveLength(64)
        ->and($result->content)->toBe('Welcome:Ada')
        ->and($result->subject)->toBe('Hello')
        ->and($snapshotResult->content)->toBe('Welcome:Ada')
        ->and($queued->id)->toBe($same->id);
    Queue::assertPushed(RenderTemplateJob::class, 1);
});

it('enforces optimistic concurrency and published version immutability', function (): void {
    $actor = TemplateActorData::system();
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    $updatedTemplate = app(UpdateTemplateAction::class)->execute(
        $template,
        new UpdateTemplateData(
            status: TemplateStatus::Active,
            expectedRevision: $template->revision,
            translations: ['en' => ['title' => 'Updated welcome']],
        ),
        $actor,
    );

    expect($updatedTemplate->revision)->toBe(2)
        ->and($updatedTemplate->getTranslation('en')?->getAttribute('title'))
        ->toBe('Updated welcome')
        ->and(TemplateManagementData::fromModel($updatedTemplate)->toArray())
        ->toHaveKeys(['id', 'key', 'translations', 'versions', 'assignments']);

    expect(fn () => app(UpdateTemplateAction::class)->execute(
        $updatedTemplate,
        new UpdateTemplateData(
            status: TemplateStatus::Active,
            expectedRevision: 1,
            translations: ['en' => ['title' => 'Stale']],
        ),
        $actor,
    ))->toThrow(StaleTemplateException::class);

    [$version] = createComposedTemplateVersion(
        $updatedTemplate,
        $actor,
        [],
        ['en' => ['text' => 'Original']],
    );
    $draft = app(UpdateTemplateVersionAction::class)->execute(
        $version,
        new UpdateTemplateVersionData(
            expectedRevision: $version->revision,
            metadata: ['campaign' => 'welcome'],
        ),
        $actor,
    );
    $published = app(PublishTemplateVersionAction::class)->execute(
        $draft,
        $draft->revision,
        $actor,
    );

    expect(fn () => app(UpdateTemplateVersionAction::class)->execute(
        $published,
        new UpdateTemplateVersionData(
            expectedRevision: $published->revision,
            metadata: ['campaign' => 'forbidden'],
        ),
        $actor,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PublishTemplateVersionAction::class)->execute(
            $published,
            $published->revision,
            $actor,
        ))->toThrow(InvalidArgumentException::class);

    $publishedRevision = $published->revision;
    [$nextVersion] = createComposedTemplateVersion(
        $updatedTemplate,
        $actor,
        [],
        ['en' => ['text' => 'Replacement']],
    );
    $replacement = app(PublishTemplateVersionAction::class)->execute(
        $nextVersion,
        $nextVersion->revision,
        $actor,
    );

    expect($published->refresh()->status)->toBe(TemplateVersionStatus::Retired)
        ->and($published->revision)->toBe($publishedRevision + 1)
        ->and($replacement->status)->toBe(TemplateVersionStatus::Published)
        ->and(fn () => app(PublishTemplateVersionAction::class)->execute(
            $published,
            $published->revision,
            $actor,
        ))->toThrow(InvalidArgumentException::class);
});

it('assigns templates to registered owners with exact revisions', function (): void {
    $actor = TemplateActorData::system();
    $owner = TestTemplateOwner::query()->create(['name' => 'Ada']);
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    $assignment = app(AssignTemplateAction::class)->execute(
        $template,
        new AssignTemplateData(
            ownerType: 'member',
            ownerId: $owner->id,
            settings: ['tone' => 'friendly'],
        ),
        $actor,
    );
    $updated = app(AssignTemplateAction::class)->execute(
        $template,
        new AssignTemplateData(
            ownerType: 'member',
            ownerId: $owner->id,
            settings: ['tone' => 'formal'],
            expectedRevision: $assignment->revision,
        ),
        $actor,
    );

    expect($updated->revision)->toBe(2)
        ->and($updated->settings)->toBe(['tone' => 'formal']);

    expect(fn () => app(UnassignTemplateAction::class)->execute(
        $updated,
        1,
        $actor,
    ))->toThrow(StaleTemplateException::class);

    expect(app(UnassignTemplateAction::class)->execute(
        $updated,
        $updated->revision,
        $actor,
    ))->toBeTrue();
});

it('authorizes every caller-selected stored render scope', function (): void {
    Queue::fake();
    $system = TemplateActorData::system();
    $owner = TestTemplateOwner::query()->create(['name' => 'Ada']);
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $system,
    );
    [$version] = createComposedTemplateVersion(
        $template,
        $system,
        [],
        ['en' => ['text' => 'Welcome']],
    );
    app(PublishTemplateVersionAction::class)->execute(
        $version,
        $version->revision,
        $system,
    );
    app(AssignTemplateAction::class)->execute(
        $template,
        new AssignTemplateData(
            ownerType: 'member',
            ownerId: $owner->id,
            settings: ['tone' => 'friendly'],
        ),
        $system,
    );
    app()->instance(TemplateAuthorization::class, new class implements TemplateAuthorization
    {
        public function authorize(
            TemplateAbility $ability,
            TemplateActorData $actor,
            array $context = [],
        ): void {
            if ($ability === TemplateAbility::Render
                && ($context['owner_type'] ?? null) === 'member'
                && ($context['owner_id'] ?? null) === $actor->id
                && ($context['profile'] ?? null) === 'default'
                && array_key_exists('version_id', $context)
                && is_bool($context['queued'] ?? null)) {
                return;
            }

            throw new AuthorizationException('The requested template render scope is forbidden.');
        }
    });
    $ownerActor = new TemplateActorData(type: 'member', id: $owner->id);
    $request = new RenderTemplateData(
        locale: 'en',
        payload: ['name' => 'Ada'],
        ownerType: 'member',
        ownerId: $owner->id,
        idempotencyKey: 'authorized-owner-render',
    );

    expect(app(RenderStoredTemplateAction::class)->execute(
        $template,
        $request,
        $ownerActor,
    )->content)->toBe('Welcome:Ada')
        ->and(app(QueueTemplateRenderAction::class)->execute(
            $template,
            $request,
            $ownerActor,
        )->status)->toBe(TemplateRenderStatus::Pending)
        ->and(fn () => app(RenderStoredTemplateAction::class)->execute(
            $template,
            $request,
            new TemplateActorData(type: 'member', id: (string) str()->uuid()),
        ))->toThrow(AuthorizationException::class);
});

it('uses canonical render requests and processes queued output idempotently', function (): void {
    Queue::fake();
    config()->set([
        'templates.rendering.output.persist' => false,
        'templates.rendering.store_payload' => false,
    ]);
    $actor = TemplateActorData::system();
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    [$version] = createComposedTemplateVersion(
        $template,
        $actor,
        [],
        ['en' => ['text' => 'Welcome']],
    );
    app(PublishTemplateVersionAction::class)->execute($version, $version->revision, $actor);
    $render = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            'en',
            ['name' => 'Ada', 'options' => ['b' => 2, 'a' => 1]],
            idempotencyKey: 'welcome-ada',
        ),
        $actor,
    );
    $same = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            'en',
            ['options' => ['a' => 1, 'b' => 2], 'name' => 'Ada'],
            idempotencyKey: 'welcome-ada',
        ),
        $actor,
    );

    expect($same->id)->toBe($render->id)
        ->and(app(CanonicalJson::class)->digest(['b' => 2, 'a' => 1]))
        ->toBe(app(CanonicalJson::class)->digest(['a' => 1, 'b' => 2]));

    expect(fn () => app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            'en',
            ['name' => 'Grace'],
            idempotencyKey: 'welcome-ada',
        ),
        $actor,
    ))->toThrow(TemplatesException::class);

    $completed = app(ProcessTemplateRenderAction::class)->execute($render);
    $again = app(ProcessTemplateRenderAction::class)->execute($completed);
    $terminal = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            'en',
            ['name' => 'Terminal'],
            idempotencyKey: 'terminal-render',
        ),
        $actor,
    );
    (new RenderTemplateJob(
        $terminal->id,
        $terminal->dispatch_generation,
    ))->failed(new RuntimeException('Terminal failure.'));

    expect($completed->status)->toBe(TemplateRenderStatus::Completed)
        ->and($completed->payload)->toBeNull()
        ->and($again->id)->toBe($completed->id)
        ->and($again->attempts)->toBe(1)
        ->and($terminal->refresh()->status)->toBe(TemplateRenderStatus::Failed)
        ->and($terminal->payload)->toBeNull()
        ->and($terminal->settings)->toBeNull();
});

it('applies durable render job policies and records processing failures', function (): void {
    Queue::fake();
    config()->set('templates.rendering.output.persist', false);
    $actor = TemplateActorData::system();
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    [$version] = createComposedTemplateVersion(
        $template,
        $actor,
        [],
        ['en' => ['text' => 'Welcome']],
    );
    app(PublishTemplateVersionAction::class)->execute($version, $version->revision, $actor);
    $render = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            locale: 'en',
            payload: ['name' => 'Ada'],
            idempotencyKey: 'job-success',
        ),
        $actor,
    );
    config()->set('templates.rendering.backoff', 'invalid');
    $job = new RenderTemplateJob($render->id, $render->dispatch_generation);

    expect($job->uniqueId())->toBe($render->id)
        ->and($job->backoff())->toBe([10, 30, 90]);

    config()->set('templates.rendering.backoff', [0, 'later']);
    expect($job->backoff())->toBe([10, 30, 90]);

    config()->set([
        'templates.rendering.backoff' => [7, 20],
        'templates.rendering.lease_seconds' => 30,
    ]);
    expect($job->middleware())->toHaveCount(1);

    $job->handle(app(ProcessTemplateRenderAction::class));

    $failed = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            locale: 'en',
            payload: ['name' => 'Grace'],
            idempotencyKey: 'job-failure',
        ),
        $actor,
    );
    $failed->version()->update(['status' => TemplateVersionStatus::Draft]);

    expect($render->refresh()->status)->toBe(TemplateRenderStatus::Completed)
        ->and(fn () => app(ProcessTemplateRenderAction::class)->execute($failed))
        ->toThrow(TemplateResolutionException::class)
        ->and($failed->refresh()->status)->toBe(TemplateRenderStatus::Failed)
        ->and($failed->failure)->toContain('immutable published version');
});

it('uses Content media fields instead of a template asset table', function (): void {
    $actor = TemplateActorData::system();
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    $media = Media::factory()->create([
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
    ]);
    Storage::disk('public')->put(app(MediaPathResolver::class)->mediaPath($media), 'logo');
    [$version, $block] = createComposedTemplateVersion(
        $template,
        $actor,
        ['logo' => $media->id],
        ['en' => ['text' => 'Welcome']],
    );
    $published = app(PublishTemplateVersionAction::class)->execute(
        $version,
        $version->revision,
        $actor,
    );
    $snapshot = $published->content_snapshot;

    expect($snapshot)->toBeInstanceOf(ContentCompositionSnapshotData::class);

    $composition = app(ContentSnapshotService::class)->render(
        $snapshot,
        'en',
        ContentActorData::system(),
    );

    expect(MediaAssociation::query()->where('associable_id', $block->id)->count())->toBe(1)
        ->and($composition->value('body.logo'))->toBeInstanceOf(PublicMedia::class);
});

it('resolves revision-aware class-template aliases through NVL Media', function (): void {
    $media = Media::factory()->create([
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
        'revision' => 3,
    ]);
    $path = app(MediaPathResolver::class)->mediaPath($media);
    Storage::disk('public')->put(
        $path,
        (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ),
    );
    $absolutePath = Storage::disk('public')->path($path);
    config()->set(
        'templates.compatibility.assets.allowed_local_roots',
        [dirname($absolutePath, 3)],
    );
    $registry = app(MediaTemplateAssetRegistry::class);
    $registry->register(new MediaTemplateAssetData(
        key: 'brand-logo',
        mediaId: $media->id,
        scope: 'document',
        type: 'logo',
        expectedRevision: 3,
    ));
    $registry->register(new MediaTemplateAssetData(
        key: 'stale-logo',
        mediaId: $media->id,
        expectedRevision: 2,
    ));
    $registry->registerAdoptionAliases(['legacy-logo' => $media->id]);
    $resolver = app(MediaTemplateAssetResolver::class);

    expect($resolver->resolve('brand-logo'))->toBe($absolutePath)
        ->and($resolver->scope('document', 'logo'))
        ->toBe(['brand-logo' => $absolutePath])
        ->and($resolver->scope('adoption'))
        ->toBe(['legacy-logo' => $absolutePath])
        ->and($resolver->resolve('unknown'))->toBeNull()
        ->and(fn () => $resolver->resolve('stale-logo'))
        ->toThrow(TemplateResolutionException::class);
});

it('fails closed across template Media alias registration and resolution', function (): void {
    $registry = app(MediaTemplateAssetRegistry::class);
    $registered = new MediaTemplateAssetData(
        key: 'registered-logo',
        mediaId: 'registered-media',
    );
    $registry->register($registered);

    expect(fn () => $registry->register($registered))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new MediaTemplateAssetData(
            key: 'blank-media',
            mediaId: ' ',
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new MediaTemplateAssetData(
            key: 'invalid-scope',
            mediaId: 'media',
            scope: 'bad scope',
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new MediaTemplateAssetData(
            key: 'invalid-variation',
            mediaId: 'media',
            variation: 'Bad!',
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new MediaTemplateAssetData(
            key: 'invalid-delivery',
            mediaId: 'media',
            delivery: 'stream',
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new MediaTemplateAssetData(
            key: 'invalid-revision',
            mediaId: 'media',
            expectedRevision: 0,
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->registerAdoptionAliases(['legacy' => 42]))
        ->toThrow(InvalidArgumentException::class)
        ->and($registry->all())->toHaveKey('registered-logo');

    $resolver = app(MediaTemplateAssetResolver::class);
    $registry->register(new MediaTemplateAssetData(
        key: 'missing-media',
        mediaId: (string) str()->uuid(),
    ));

    expect(fn () => $resolver->resolve('missing-media'))
        ->toThrow(TemplateResolutionException::class);

    $media = Media::factory()->create([
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'is_public' => true,
        'visibility' => MediaVisibility::Public,
        'status' => MediaLifecycleStatus::Available,
        'revision' => 2,
    ]);
    Storage::disk('public')->put(app(MediaPathResolver::class)->mediaPath($media), 'logo');
    config()->set([
        'filesystems.disks.public.url' => null,
        'templates.pdf.remote_assets.enabled' => true,
        'templates.pdf.remote_assets.allow_http' => true,
        'templates.pdf.remote_assets.allowed_hosts' => ['localhost'],
    ]);
    url()->forceRootUrl('http://localhost');
    $registry->register(new MediaTemplateAssetData(
        key: 'missing-variation',
        mediaId: $media->id,
        variation: 'thumbnail',
    ));
    $registry->register(new MediaTemplateAssetData(
        key: 'public-url',
        mediaId: $media->id,
        delivery: 'url',
    ));

    expect(fn () => $resolver->resolve('missing-variation'))
        ->toThrow(TemplateResolutionException::class)
        ->and(fn () => $resolver->resolve('public-url'))
        ->toThrow(TemplateResolutionException::class);

    $nullResolver = new NullTemplateAssetResolver;

    expect($nullResolver->resolve('anything'))->toBeNull()
        ->and($nullResolver->scope('anything'))->toBe([]);
});

it('plans prepares applies and reconciles an idempotent staged adoption manifest', function (): void {
    Schema::create('legacy_template_assets', function (Blueprint $table): void {
        $table->id();
        $table->string('alias');
        $table->index('alias', 'template_assets_alias_index');
    });
    $media = Media::factory()->create([
        'status' => MediaLifecycleStatus::Available,
        'revision' => 4,
    ]);
    $manifestPath = storage_path('framework/testing/templates-adoption.json');
    File::ensureDirectoryExists(dirname($manifestPath));
    File::put($manifestPath, (string) json_encode([
        'version' => 1,
        'staging_tables' => ['legacy_template_assets'],
        'legacy_asset_count' => 1,
        'templates' => [[
            'legacy_key' => 'legacy-welcome',
            'key' => 'welcome',
            'translations' => [
                'en' => ['title' => 'Adopted welcome'],
                'bg' => ['title' => 'Приветствие'],
            ],
        ]],
        'content' => [[
            'legacy_key' => 'legacy-welcome-copy',
            'legacy_scope' => 'legacy-global',
            'legacy_scope_key' => 'legacy',
            'definition' => 'template-copy',
            'key' => 'welcome-copy',
            'scope' => 'global',
            'scope_key' => '*',
            'translations' => [
                'en' => ['text' => 'Adopted copy'],
                'bg' => ['text' => 'Приет текст'],
            ],
            'publish' => true,
        ]],
        'assets' => [[
            'legacy_alias' => 'legacy-logo',
            'key' => 'adopted-logo',
            'media_id' => $media->id,
            'scope' => 'document',
            'type' => 'logo',
            'expected_revision' => 4,
        ]],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('nvl:templates:adopt', [
        'manifest' => $manifestPath,
        '--format' => 'json',
    ])->assertSuccessful()
        ->expectsOutputToContain('"mode": "plan"');
    expect(collect(Schema::getIndexes('legacy_template_assets'))->pluck('name'))
        ->toContain('template_assets_alias_index');

    $this->artisan('nvl:templates:adopt', [
        'manifest' => $manifestPath,
        '--prepare' => true,
        '--format' => 'json',
    ])->assertSuccessful()
        ->expectsOutputToContain('"operation": "dropped"');

    expect(collect(Schema::getIndexes('legacy_template_assets'))->pluck('name'))
        ->not->toContain('template_assets_alias_index')
        ->and(Template::query()->where('key', 'welcome')->count())->toBe(0);

    $this->artisan('nvl:templates:adopt', [
        'manifest' => $manifestPath,
        '--apply' => true,
        '--format' => 'json',
    ])->assertSuccessful()
        ->expectsOutputToContain('"healthy": true');

    expect(Template::query()->where('key', 'welcome')->count())->toBe(1)
        ->and(ContentBlock::query()
            ->where('scope', 'global')
            ->where('scope_key', '*')
            ->where('key', 'welcome-copy')
            ->where('status', ContentStatus::Published->value)
            ->count())->toBe(1)
        ->and(app(MediaTemplateAssetRegistry::class)->get('adopted-logo')?->mediaId)
        ->toBe($media->id);

    $this->artisan('nvl:templates:adopt', [
        'manifest' => $manifestPath,
        '--apply' => true,
        '--format' => 'json',
    ])->assertSuccessful()
        ->expectsOutputToContain('"unchanged": 1');

    File::delete($manifestPath);
});

it('rejects malformed adoption manifests before mutating consumer data', function (): void {
    $manifests = app(TemplateAdoptionManifest::class);
    $valid = [
        'version' => 1,
        'staging_tables' => [],
        'legacy_asset_count' => 0,
        'templates' => [],
        'content' => [],
        'assets' => [],
    ];
    $invalidTopLevelManifests = [
        [...$valid, 'unknown' => true],
        [...$valid, 'version' => 2],
        [...$valid, 'staging_connection' => ' '],
        [...$valid, 'staging_tables' => ['table' => 'legacy_templates']],
        [...$valid, 'staging_tables' => ['unsafe-table']],
        [...$valid, 'templates' => ['template' => []]],
        [...$valid, 'templates' => [[]]],
        [...$valid, 'legacy_asset_count' => -1],
        [...$valid, 'legacy_asset_count' => 1],
    ];

    foreach ($invalidTopLevelManifests as $manifest) {
        expect(fn () => $manifests->normalize($manifest))
            ->toThrow(InvalidArgumentException::class);
    }

    config()->set('templates.adoption.maximum_records', 0);
    expect(fn () => $manifests->normalize($valid))
        ->toThrow(InvalidArgumentException::class);

    config()->set('templates.adoption.maximum_records', 1);
    expect(fn () => $manifests->normalize([
        ...$valid,
        'templates' => [
            ['legacy_key' => 'first'],
            ['legacy_key' => 'second'],
        ],
    ]))->toThrow(InvalidArgumentException::class);
    config()->set('templates.adoption.maximum_records', 10_000);

    $template = [
        'legacy_key' => 'legacy-welcome',
        'key' => 'welcome',
        'status' => 'active',
        'metadata' => [],
        'translations' => ['en' => ['title' => 'Welcome']],
    ];
    $invalidTemplates = [
        [...$template, 'unexpected' => true],
        [...$template, 'legacy_key' => ' '],
        [...$template, 'status' => 'unsupported'],
        [...$template, 'status' => 1],
        [...$template, 'metadata' => ['not-an-object']],
        [...$template, 'metadata' => [1 => 'not-a-string-key']],
        [...$template, 'translations' => ['en' => ['title' => '']]],
        [...$template, 'translations' => ['en' => [
            'title' => 'Welcome',
            'description' => 42,
        ]]],
    ];

    foreach ($invalidTemplates as $item) {
        expect(fn () => $manifests->normalize([
            ...$valid,
            'templates' => [$item],
        ]))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => $manifests->normalize([
        ...$valid,
        'templates' => [$template, $template],
    ]))->toThrow(InvalidArgumentException::class);

    $content = [
        'legacy_key' => 'legacy-copy',
        'legacy_scope' => 'legacy-global',
        'legacy_scope_key' => 'legacy',
        'definition' => 'template-copy',
        'key' => 'welcome-copy',
        'scope' => 'global',
        'scope_key' => '*',
        'visibility' => 'public',
        'publish' => true,
    ];

    expect(fn () => $manifests->normalize([
        ...$valid,
        'content' => [[...$content, 'visibility' => 'unsupported']],
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manifests->normalize([
            ...$valid,
            'content' => [[...$content, 'publish' => 'yes']],
        ]))->toThrow(InvalidArgumentException::class);

    $media = Media::factory()->create([
        'status' => MediaLifecycleStatus::Available,
        'revision' => 2,
    ]);
    $asset = [
        'legacy_alias' => 'legacy-logo',
        'key' => 'logo',
        'media_id' => $media->id,
        'expected_revision' => 2,
    ];

    expect(fn () => $manifests->normalize([
        ...$valid,
        'legacy_asset_count' => 1,
        'assets' => [[...$asset, 'expected_revision' => 0]],
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manifests->normalize([
            ...$valid,
            'legacy_asset_count' => 1,
            'assets' => [[...$asset, 'media_id' => (string) str()->uuid()]],
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manifests->normalize([
            ...$valid,
            'legacy_asset_count' => 1,
            'assets' => [[...$asset, 'expected_revision' => 1]],
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manifests->normalize([
            ...$valid,
            'legacy_asset_count' => 1,
            'assets' => [[...$asset, 'variation' => 'thumbnail']],
        ]))->toThrow(InvalidArgumentException::class);
});

it('renders a bounded source-controlled PDF and validates its payload schema', function (): void {
    $actor = TemplateActorData::system();
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'pdf-report',
            translations: ['en' => ['title' => 'PDF report']],
        ),
        $actor,
    );
    [$version] = createComposedTemplateVersion(
        $template,
        $actor,
        [],
        ['en' => ['heading' => 'Account report', 'subject' => 'Report']],
    );
    app(PublishTemplateVersionAction::class)->execute($version, $version->revision, $actor);
    $result = app(RenderStoredTemplateAction::class)->execute(
        $template,
        new RenderTemplateData('en', ['name' => 'Ada']),
        $actor,
    );

    expect($result->mimeType)->toBe('application/pdf')
        ->and($result->subject)->toBe('Report')
        ->and($result->suggestedFilename)->toBe('report.pdf')
        ->and(str_starts_with($result->content, '%PDF-'))->toBeTrue();

    $direct = app(RenderTemplateAction::class)->execute(new RenderableTemplate(
        key: 'direct-pdf',
        data: ['content' => 'Grace'],
        options: new TemplateOptions(
            renderer: 'pdf',
            locale: 'en',
            subject: 'Direct PDF',
            filename: 'direct.pdf',
            pdf: new PdfOptions(
                pageSize: PdfPageSize::A5,
                orientation: PdfOrientation::Landscape,
                margins: new PdfMargins(left: 10, right: 10),
                headerView: 'nvl-templates::pdf.header',
                headerData: ['header' => 'Internal'],
                footerView: 'nvl-templates::pdf.footer',
                watermark: 'DRAFT',
                watermarkOpacity: 0.08,
                compress: true,
            ),
        ),
    ));

    expect($direct->mimeType)->toBe('application/pdf')
        ->and($direct->suggestedFilename)->toBe('direct.pdf')
        ->and($direct->byteSize)->toBe(strlen($direct->content))
        ->and(str_starts_with($direct->content, '%PDF-'))->toBeTrue();

    $forbiddenTemporaryPath = sys_get_temp_dir().'/nvl-templates-forbidden-'.str()->uuid();
    config()->set('templates.pdf.temp_path', $forbiddenTemporaryPath);

    expect(fn () => app(PdfTemporaryDirectoryResolver::class)->resolve())
        ->toThrow(InvalidArgumentException::class)
        ->and(File::exists($forbiddenTemporaryPath))->toBeFalse();

    expect(fn () => app(RenderStoredTemplateAction::class)->execute(
        $template,
        new RenderTemplateData('en', ['unknown' => true]),
        $actor,
    ))->toThrow(TemplateResolutionException::class);

    expect(fn () => app(PdfHtmlGuard::class)->validate(
        '<img src="https://assets.example.test/logo.png">',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(PdfHtmlGuard::class)->validate(
        '<img src=" https://assets.example.test/logo.png ">',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(PdfHtmlGuard::class)->validate(
        '<style>body { background: url("\\68 ttps://assets.example.test/logo.png"); }</style>',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(PdfHtmlGuard::class)->validate(
        '<style>@import "safe.css";</style>',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(PdfHtmlGuard::class)->validate(
        '<img src="data:image/png;base64,'.base64_encode('<svg/>').'">',
    ))->toThrow(InvalidArgumentException::class);

    config()->set([
        'templates.pdf.remote_assets.enabled' => true,
        'templates.pdf.remote_assets.allowed_hosts' => ['assets.example.test'],
    ]);
    app(PdfHtmlGuard::class)->validate(
        '<div style="background-image: url( https://assets.example.test/logo.png )"></div>',
    );

    expect(fn () => app(PdfHtmlGuard::class)->validate(
        '<img src="https://assets.example.test:8443/logo.png">',
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects non-JSON values and unsupported schema or route configuration', function (): void {
    $guard = app(TemplateContentGuard::class);
    $validator = app(ConfiguredTemplatePayloadValidator::class);

    expect(fn () => $guard->payload(['object' => new stdClass]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->payload([['name' => 'Ada']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateSchema([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'pattern' => '^Ada$'],
            ],
        ]))
        ->toThrow(InvalidArgumentException::class);

    config()->set('templates.routes.render.middleware', []);

    expect(fn () => TemplatesRouteConfiguration::middleware('render'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects ancestor symlink escapes and inline SVG assets', function (): void {
    $allowedRoot = sys_get_temp_dir().'/nvl-templates-allowed-'.str()->uuid();
    $outsideRoot = sys_get_temp_dir().'/nvl-templates-outside-'.str()->uuid();
    $link = $allowedRoot.'/linked';
    File::ensureDirectoryExists($allowedRoot);
    File::ensureDirectoryExists($outsideRoot);

    if (! function_exists('symlink') || ! symlink($outsideRoot, $link)) {
        $this->markTestSkipped('The filesystem does not support symbolic links.');
    }

    config()->set('templates.rendering.output.allowed_local_roots', [$allowedRoot]);

    try {
        expect(fn () => app(SafeFilesystemPathResolver::class)->file(
            $link.'/nested/report.pdf',
            [$allowedRoot],
            createParent: true,
            requiredExtension: 'pdf',
        ))->toThrow(InvalidArgumentException::class)
            ->and(File::exists($outsideRoot.'/nested'))->toBeFalse()
            ->and(fn () => app(TestClassPdfTemplate::class)->addInlineAsset(
                'logo',
                'data:image/svg+xml;base64,'.base64_encode('<svg/>'),
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(TestClassPdfTemplate::class)->addInlineAsset(
                'logo',
                'data:image/png;base64,'.base64_encode('<svg/>'),
            ))->toThrow(InvalidArgumentException::class);
    } finally {
        if (is_link($link)) {
            unlink($link);
        }

        File::deleteDirectory($allowedRoot);
        File::deleteDirectory($outsideRoot);
    }
});

it('snapshots queued assignment settings and recovers expired render leases', function (): void {
    Queue::fake();
    config()->set('templates.rendering.output.persist', false);
    $actor = TemplateActorData::system();
    $owner = TestTemplateOwner::query()->create(['name' => 'Ada']);
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        $actor,
    );
    [$version] = createComposedTemplateVersion(
        $template,
        $actor,
        [],
        ['en' => ['text' => 'Welcome']],
    );
    app(PublishTemplateVersionAction::class)->execute($version, $version->revision, $actor);
    $assignment = app(AssignTemplateAction::class)->execute(
        $template,
        new AssignTemplateData(
            ownerType: 'member',
            ownerId: $owner->id,
            settings: ['tone' => 'friendly'],
        ),
        $actor,
    );
    $render = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            locale: 'en',
            payload: ['name' => 'Ada'],
            ownerType: 'member',
            ownerId: $owner->id,
            idempotencyKey: 'durable-friendly',
        ),
        $actor,
    );
    $updatedAssignment = app(AssignTemplateAction::class)->execute(
        $template,
        new AssignTemplateData(
            ownerType: 'member',
            ownerId: $owner->id,
            settings: ['tone' => 'formal'],
            expectedRevision: $assignment->revision,
        ),
        $actor,
    );
    app(UnassignTemplateAction::class)->execute(
        $updatedAssignment,
        $updatedAssignment->revision,
        $actor,
    );
    $resolved = app(StoredTemplateRenderResolver::class)->resolveDurable($render->refresh());
    $completed = app(ProcessTemplateRenderAction::class)->execute($render);
    $recoverable = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            locale: 'en',
            payload: ['name' => 'Grace'],
            idempotencyKey: 'recoverable-render',
        ),
        $actor,
    );
    $stalePending = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            locale: 'en',
            payload: ['name' => 'Lin'],
            idempotencyKey: 'stale-pending-render',
        ),
        $actor,
    );
    $oldJob = new RenderTemplateJob(
        $recoverable->id,
        $recoverable->dispatch_generation,
    );
    $recoverable->forceFill([
        'status' => TemplateRenderStatus::Processing,
        'processing_token' => $oldJob->processingToken,
        'lease_expires_at' => now()->subSecond(),
    ])->save();
    $this->travel(661)->seconds();
    $recovered = app(RecoverStaleTemplateRendersAction::class)->execute();
    $oldJob->failed(new RuntimeException('Superseded delivery failed.'));

    expect($render->profile)->toBe('default')
        ->and($render->settings)->toBe(['tone' => 'friendly'])
        ->and($render->assignment()->exists())->toBeFalse()
        ->and($resolved->renderable->settings)->toBe(['tone' => 'friendly'])
        ->and($completed->status)->toBe(TemplateRenderStatus::Completed)
        ->and($recovered->modelKeys())->toHaveCount(2)
        ->and($recovered->modelKeys())->toContain($recoverable->id, $stalePending->id)
        ->and($recoverable->refresh()->status)->toBe(TemplateRenderStatus::Pending)
        ->and($recoverable->dispatch_generation)->toBe(1)
        ->and($recoverable->processing_token)->toBeNull()
        ->and($recoverable->lease_expires_at)->toBeNull()
        ->and($stalePending->refresh()->status)->toBe(TemplateRenderStatus::Pending)
        ->and($stalePending->dispatch_generation)->toBe(1);
    Queue::assertPushed(
        RenderTemplateJob::class,
        fn (RenderTemplateJob $job): bool => $job->renderId === $stalePending->id
            && $job->dispatchGeneration === 1,
    );
});

it('scopes render history to its requester and exposes only transport-safe facts', function (): void {
    app()->instance(TemplateAuthorization::class, new class implements TemplateAuthorization
    {
        public function authorize(
            TemplateAbility $ability,
            TemplateActorData $actor,
            array $context = [],
        ): void {}
    });
    Queue::fake();
    $actor = new TemplateActorData(
        type: GenericUser::class,
        id: (string) str()->uuid(),
    );
    $otherActor = new TemplateActorData(type: 'member', id: (string) str()->uuid());
    $template = app(CreateTemplateAction::class)->execute(
        new CreateTemplateData(
            key: 'welcome',
            translations: ['en' => ['title' => 'Welcome']],
        ),
        TemplateActorData::system(),
    );
    [$version] = createComposedTemplateVersion(
        $template,
        TemplateActorData::system(),
        [],
        ['en' => ['text' => 'Welcome']],
    );
    app(PublishTemplateVersionAction::class)->execute(
        $version,
        $version->revision,
        TemplateActorData::system(),
    );
    $render = app(QueueTemplateRenderAction::class)->execute(
        $template,
        new RenderTemplateData(
            locale: 'en',
            payload: ['name' => 'Ada'],
            idempotencyKey: 'owned-render',
        ),
        $actor,
    );
    $page = app(ListTemplateRendersAction::class)->execute(
        FilterSet::none(),
        $actor,
    );
    $transport = TemplateRenderData::fromModel(
        app(GetTemplateRenderAction::class)->execute($render, $actor),
    )->toArray();
    $request = Request::create('/renders/'.$render->id);
    $request->setUserResolver(
        static fn (): GenericUser => new GenericUser(['id' => $actor->id]),
    );
    $response = app(TemplateRenderController::class)->show(
        $request,
        $render,
        app(TemplateActorFactory::class),
        app(GetTemplateRenderAction::class),
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($render->id)
        ->and($transport)->toHaveKeys([
            'id',
            'templateId',
            'templateVersionId',
            'locale',
            'profile',
            'status',
        ])
        ->and($transport)->not->toHaveKeys([
            'payload',
            'settings',
            'failure',
            'processingToken',
        ])
        ->and($response->headers->get('cache-control'))->toContain('no-store');

    expect(fn () => app(GetTemplateRenderAction::class)->execute($render, $otherActor))
        ->toThrow(AuthorizationException::class);
});
