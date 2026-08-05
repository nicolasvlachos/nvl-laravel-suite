<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Templates\Contracts\TemplateAssetResolver;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Contracts\TemplatePayloadValidator;
use Nvl\Templates\Data\PdfOptions as TypedPdfOptions;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Data\TemplateDefinitionData;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Exceptions\TemplateResolutionException;
use Nvl\Templates\Html\TemplateContext;
use Nvl\Templates\Html\TemplateRenderer;
use Nvl\Templates\Jobs\RenderTemplateJob;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Pdf\Contracts\PdfServiceInterface;
use Nvl\Templates\Pdf\Options\PdfOptions as FluentPdfOptions;
use Nvl\Templates\Rendering\TemplateRenderContext;
use Nvl\Templates\Services\ConfiguredTemplatePayloadValidator;
use Nvl\Templates\Services\PdfOptionsResolver;
use Nvl\Templates\Services\SafeFilesystemPathResolver;
use Nvl\Templates\Services\StoredTemplateOptionsFactory;
use Nvl\Templates\Services\TemplateAssetGuard;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Services\TemplateOptionsResolver;
use Nvl\Templates\Services\TemplateOutputGuard;
use Nvl\Templates\Services\TemplateOwnerRegistry;
use Nvl\Templates\Services\TemplateRendererRegistry;
use Nvl\Templates\Support\PdfConfig\Data\HeaderFooterData;
use Nvl\Templates\Support\PdfConfig\Data\MarginsData;
use Nvl\Templates\Support\PdfConfig\Data\MetadataData;
use Nvl\Templates\Support\PdfConfig\Data\PageNumberingData;
use Nvl\Templates\Support\PdfConfig\Data\ProtectionData;
use Nvl\Templates\Support\PdfConfig\Data\WatermarkData;
use Nvl\Templates\Support\PdfConfig\EngineConfig;
use Nvl\Templates\Support\PdfConfig\Enums\PageOrientation;
use Nvl\Templates\Support\PdfConfig\Enums\PaperSize;
use Nvl\Templates\Support\TemplatesRouteConfiguration;
use Nvl\Templates\Support\View\AssetAccessor;
use Nvl\Templates\Support\View\ContentAccessor;
use Nvl\Templates\Template as RenderableTemplate;
use Nvl\Templates\Tests\Fixtures\TestClassPdfTemplate;
use Nvl\Templates\Tests\Fixtures\TestTemplateOwner;
use Nvl\Templates\Tests\Fixtures\TestTemplateOwnerResolver;
use Nvl\Templates\Tests\Fixtures\TestTemplateRenderer;

beforeEach(function (): void {
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
    app()->instance(TemplateAuthorization::class, new class implements TemplateAuthorization
    {
        public function authorize(
            TemplateAbility $ability,
            TemplateActorData $actor,
            array $context = [],
        ): void {}
    });
});

it('supports the complete opt-in management and render HTTP workflow', function (): void {
    Queue::fake();
    $this->be(new GenericUser(['id' => 'consumer-actor']));
    config()->set([
        'templates.routes.management.enabled' => true,
        'templates.routes.management.middleware' => ['api'],
        'templates.routes.render.enabled' => true,
        'templates.routes.render.middleware' => ['api'],
    ]);
    require __DIR__.'/../../routes/api.php';
    Route::getRoutes()->refreshNameLookups();

    $owner = TestTemplateOwner::query()->create(['name' => 'Ada']);
    $created = $this->postJson('/api/v1/templates', [
        'key' => 'welcome',
        'status' => 'active',
        'translations' => [
            'en' => ['title' => 'Welcome', 'description' => 'Greeting'],
        ],
    ])->assertCreated()->json('data');

    expect($created)->toBeArray();
    $templateId = $created['id'];

    $this->getJson('/api/v1/templates?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 1);
    $this->getJson("/api/v1/templates/{$templateId}")
        ->assertOk()
        ->assertJsonPath('data.key', 'welcome');
    $updated = $this->putJson("/api/v1/templates/{$templateId}", [
        'status' => 'active',
        'expectedRevision' => $created['revision'],
        'metadata' => ['channel' => 'email'],
        'translations' => ['en' => ['title' => 'Updated welcome']],
    ])->assertOk()->json('data');

    $version = $this->postJson("/api/v1/templates/{$templateId}/versions", [
        'metadata' => ['campaign' => 'onboarding'],
    ])->assertCreated()->json('data');
    $versionId = $version['id'];
    $version = $this->putJson("/api/v1/templates/versions/{$versionId}", [
        'expectedRevision' => $version['revision'],
        'metadata' => ['campaign' => 'activation'],
    ])->assertOk()->json('data');

    $content = app(CreateContentBlockAction::class)->execute(
        new CreateContentBlockData(
            definition: 'template-copy',
            key: "http-template-{$versionId}",
            values: [],
            translations: ['en' => ['text' => 'Welcome', 'subject' => 'Hello']],
        ),
        ContentActorData::system(),
    );
    app(PlaceContentBlockAction::class)->execute(
        $content,
        TemplateVersion::query()->findOrFail($versionId),
        TemplateVersion::CONTENT_GROUP,
        new PlaceContentBlockData(key: 'body', region: 'main'),
        ContentActorData::system(),
    );

    $published = $this->postJson("/api/v1/templates/versions/{$versionId}/publish", [
        'expectedRevision' => $version['revision'],
    ])->assertOk()->json('data');

    $assignment = $this->putJson("/api/v1/templates/{$templateId}/assignments", [
        'ownerType' => 'member',
        'ownerId' => $owner->id,
        'profile' => 'default',
        'versionId' => $published['id'],
        'settings' => ['tone' => 'formal'],
        'expectedRevision' => 0,
    ])->assertOk()->json('data');

    $inline = $this->postJson('/api/v1/templates/render/welcome', [
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'ownerType' => 'member',
        'ownerId' => $owner->id,
    ])->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8')
        ->assertContent('Welcome:Ada');

    $queuedResponse = $this->postJson('/api/v1/templates/render/welcome/queue', [
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'ownerType' => 'member',
        'ownerId' => $owner->id,
        'idempotencyKey' => 'http-welcome-ada',
    ])->assertAccepted();
    $queued = $queuedResponse->json('data');

    $this->getJson('/api/v1/templates/render/renders')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/templates/render/renders/'.$queued['id'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.payload');
    $this->deleteJson('/api/v1/templates/assignments/'.$assignment['id'], [
        'expectedRevision' => $assignment['revision'],
    ])->assertOk()->assertJsonPath('data.deleted', true);

    expect($updated['metadata'])->toBe(['channel' => 'email'])
        ->and($inline->headers->get('cache-control'))->toContain('private', 'no-store')
        ->and($queuedResponse->headers->get('cache-control'))->toContain('private', 'no-store')
        ->and(Template::query()->count())->toBe(1)
        ->and(TemplateAssignment::query()->count())->toBe(0)
        ->and(TemplateRender::query()->count())->toBe(1)
        ->and(Route::has('nvl.templates.management.index'))->toBeTrue()
        ->and(Route::has('nvl.templates.render.execute'))->toBeTrue();
    Queue::assertPushed(RenderTemplateJob::class, 1);
});

it('validates the supported JSON schema surface and every bounded value type', function (): void {
    $validator = app(TemplatePayloadValidator::class);
    $schema = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20],
            'age' => ['type' => 'integer', 'minimum' => 18, 'maximum' => 120],
            'score' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 10.5],
            'active' => ['type' => 'boolean'],
            'middleName' => ['type' => 'string', 'nullable' => true],
            'empty' => ['type' => 'null'],
            'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 1],
                'minItems' => 1,
                'maxItems' => 3,
            ],
            'address' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['name', 'age', 'tags', 'address'],
        'additionalProperties' => false,
    ];
    $payload = [
        'name' => 'Ada',
        'age' => 37,
        'score' => 9.5,
        'active' => true,
        'middleName' => null,
        'empty' => null,
        'role' => 'admin',
        'tags' => ['php', 'laravel'],
        'address' => ['city' => 'Sofia'],
    ];

    $validator->validateSchema([]);
    $validator->validate([], []);
    $validator->validateSchema($schema);
    $validator->validate($schema, $payload);
    $validator->validate([
        'name' => ['type' => 'string', 'required' => true],
    ], ['name' => 'Ada']);

    $invalidPayloads = [
        ['path' => 'name', 'value' => 'A'],
        ['path' => 'name', 'value' => str_repeat('a', 21)],
        ['path' => 'age', 'value' => 17],
        ['path' => 'age', 'value' => 121],
        ['path' => 'score', 'value' => 0.1],
        ['path' => 'score', 'value' => 11.0],
        ['path' => 'role', 'value' => 'guest'],
        ['path' => 'tags', 'value' => []],
        ['path' => 'tags', 'value' => ['a', 'b', 'c', 'd']],
        ['path' => 'address', 'value' => []],
        ['path' => 'address', 'value' => ['city' => 'Sofia', 'zip' => '1000']],
    ];

    foreach ($invalidPayloads as $invalid) {
        $candidate = $payload;
        $candidate[$invalid['path']] = $invalid['value'];

        expect(fn () => $validator->validate($schema, $candidate))
            ->toThrow(TemplateResolutionException::class);
    }

    unset($payload['name']);
    expect(fn () => $validator->validate($schema, $payload))
        ->toThrow(TemplateResolutionException::class);
});

it('rejects malformed JSON schemas and unsafe route group configuration', function (): void {
    $validator = app(ConfiguredTemplatePayloadValidator::class);
    $invalidSchemas = [
        ['type' => 'unsupported'],
        ['type' => 'string', 'pattern' => 'forbidden'],
        ['type' => 'string', 'nullable' => 'yes'],
        ['type' => 'string', 'enum' => []],
        ['type' => 'integer', 'enum' => ['one']],
        ['type' => 'array', 'items' => 'string'],
        ['type' => 'number', 'minimum' => INF],
        ['type' => 'string', 'minLength' => -1],
        ['type' => 'string', 'minLength' => 5, 'maxLength' => 2],
        ['type' => 'object', 'properties' => [0 => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['bad key' => ['type' => 'string']]],
        ['type' => 'object', 'required' => 'name'],
        ['type' => 'object', 'required' => ['missing']],
        [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name', 'name'],
        ],
        ['type' => 'object', 'additionalProperties' => 'no'],
    ];

    foreach ($invalidSchemas as $schema) {
        expect(fn () => $validator->validateSchema($schema))
            ->toThrow(InvalidArgumentException::class);
    }

    $routeConfigurations = [
        ['key' => 'prefix', 'value' => 42, 'method' => 'path'],
        ['key' => 'prefix', 'value' => '../unsafe', 'method' => 'path'],
        ['key' => 'name', 'value' => [], 'method' => 'name'],
        ['key' => 'name', 'value' => 'bad name', 'method' => 'name'],
        ['key' => 'middleware', 'value' => 'api', 'method' => 'middleware'],
        ['key' => 'middleware', 'value' => [], 'method' => 'middleware'],
        ['key' => 'middleware', 'value' => ['api', ''], 'method' => 'middleware'],
    ];

    foreach ($routeConfigurations as $configuration) {
        config()->set(
            'templates.routes.probe.'.$configuration['key'],
            $configuration['value'],
        );

        expect(fn () => TemplatesRouteConfiguration::{$configuration['method']}('probe'))
            ->toThrow(InvalidArgumentException::class);
    }

    config()->set([
        'templates.routes.probe.prefix' => '/api/v2/templates/',
        'templates.routes.probe.name' => 'consumer.templates',
        'templates.routes.probe.middleware' => ['api', 'auth'],
    ]);

    expect(TemplatesRouteConfiguration::path('probe'))->toBe('api/v2/templates')
        ->and(TemplatesRouteConfiguration::name('probe'))->toBe('consumer.templates.')
        ->and(TemplatesRouteConfiguration::middleware('probe'))->toBe(['api', 'auth']);
});

it('covers the complete fluent PDF configuration adapter', function (): void {
    $config = (new EngineConfig)
        ->setPageSize(PaperSize::LETTER)
        ->setOrientation(PageOrientation::LANDSCAPE)
        ->setMargins(new MarginsData(10, 11, 12, 13, 4, 5))
        ->setMetadata(new MetadataData(
            title: 'Statement',
            author: 'NVL',
            creator: 'Templates',
            subject: 'Account',
            keywords: 'account,statement',
        ))
        ->setProtection(new ProtectionData(
            enabled: true,
            permissions: ['print'],
            userPassword: 'user',
            ownerPassword: 'owner',
        ))
        ->setWatermark(new WatermarkData('DRAFT', 0.2, true))
        ->setPageNumbering(new PageNumberingData(
            enabled: true,
            position: 'bottom-right',
        ))
        ->setHeaderFooter(new HeaderFooterData(
            headerHtml: '<p>Header</p>',
            footerHtml: '<p>Footer</p>',
        ))
        ->setDefaultFont('dejavusans')
        ->setDpi(144)
        ->setTempDir(storage_path('framework/cache'))
        ->setImageQuality(90)
        ->enableCompression(false);
    $data = $config->toPdfOptions();
    $array = $config->toArray();
    $fluent = FluentPdfOptions::a4Portrait()
        ->paper(PaperSize::A5)
        ->orientation(PageOrientation::LANDSCAPE)
        ->margins(new MarginsData(1, 2, 3, 4))
        ->metadata(new MetadataData(title: 'Fluent'))
        ->protection(new ProtectionData)
        ->watermark(new WatermarkData)
        ->pageNumbering(new PageNumberingData)
        ->headerFooter(new HeaderFooterData)
        ->dpi(120)
        ->imageQuality(80)
        ->debugImages();

    expect($data->pageSize->value)->toBe('Letter')
        ->and($data->orientation->value)->toBe('landscape')
        ->and($data->watermark)->toBe('DRAFT')
        ->and($data->protectionPermissions)->toBe(['print'])
        ->and($array['orientation'])->toBe('L')
        ->and($array['dpi'])->toBe(144)
        ->and($array['compress'])->toBeFalse()
        ->and($fluent->toData()->showImageErrors)->toBeTrue()
        ->and($fluent->toMpdfConfig()['jpgQuality'])->toBe(80)
        ->and(fn () => (new EngineConfig)->setDefaultFont('bad font'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new EngineConfig)->setDpi(20))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new EngineConfig)->setTempDir('relative'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new EngineConfig)->setImageQuality(0))
        ->toThrow(InvalidArgumentException::class);

    File::deleteDirectory(storage_path('framework/cache/nvl-templates'));
});

it('supports the complete class-template compatibility workflow', function (): void {
    $assetPath = storage_path('app/template-contracts/logo.png');
    File::ensureDirectoryExists(dirname($assetPath));
    File::put(
        $assetPath,
        (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ),
    );
    $resolver = new class($assetPath) implements TemplateAssetResolver
    {
        public function __construct(private readonly string $assetPath) {}

        public function resolve(string $key): ?string
        {
            return in_array($key, ['frame', 'sticker', 'extra'], true)
                ? $this->assetPath
                : null;
        }

        public function scope(string $scope, ?string $type = null): array
        {
            if ($scope !== 'document') {
                return [];
            }

            return match ($type) {
                'frame' => ['frame-scope' => $this->assetPath],
                'sticker' => ['sticker-scope' => $this->assetPath],
                default => ['scope-asset' => $this->assetPath],
            };
        }
    };
    $template = new TestClassPdfTemplate(
        app(Factory::class),
        app(TemplateContentGuard::class),
        app(TemplateAssetGuard::class),
        app(PdfServiceInterface::class),
        $resolver,
    );
    $schemaPrototype = new class
    {
        /** @var array<string, mixed> */
        private array $values = [];

        /**
         * @param  array<string, mixed>  $values
         */
        public static function from(array $values): self
        {
            $instance = new self;
            $instance->values = $values;

            return $instance;
        }

        /**
         * @return array<string, mixed>
         */
        public function toArray(): array
        {
            return $this->values;
        }
    };
    $schemaClass = $schemaPrototype::class;
    $inline = 'data:image/png;base64,'.base64_encode(File::get($assetPath));

    expect(TestClassPdfTemplate::metadata()->title)->toBe('Test Class Pdf Template')
        ->and($template->isMultivariate())->toBeTrue()
        ->and(fn () => $template->variant('BAD VALUE'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->multivariate(false)->variant('simple'))
        ->toThrow(InvalidArgumentException::class);

    $template->multivariate()
        ->variant('premium')
        ->withFallbackLanguage(null)
        ->withFallbackLanguage('EN_us')
        ->defaultFrame(null)
        ->defaultFrame('missing')
        ->defaultFrame('frame')
        ->useFrame('frame')
        ->useStickers()
        ->addStickerSrc($assetPath, [
            'x_mm' => 1.5,
            'y_mm' => 2.5,
            'w_mm' => 10.0,
            'h_mm' => 5.0,
            'rotate' => 15.0,
        ])
        ->addStickerByKey('sticker')
        ->addAssetByKey('extra', 'resolved-extra')
        ->addAssetsByScope('document')
        ->addStickersByScope('document')
        ->addFramesByScope('document')
        ->setOrientation(PageOrientation::LANDSCAPE)
        ->setMargins(new MarginsData(10, 10, 10, 10))
        ->setProtection(new ProtectionData)
        ->setWatermark(new WatermarkData('INTERNAL', 0.1, true))
        ->setPageNumbering(new PageNumberingData)
        ->setFooterHtml('<p>Footer</p>')
        ->setLanguage('EN_us')
        ->withContent([
            'heading' => 'Statement',
            'shared' => ['legal' => 'Private'],
        ])
        ->dataClass($schemaClass)
        ->setData([
            'recipientName' => 'Ada',
            'nestedValues' => ['camelKey' => 'normalized'],
        ])
        ->setDataObject($schemaClass::from(['recipientName' => 'Grace']))
        ->setVariable('reference', 'REF-200')
        ->setOption('reference', 'REF-200')
        ->setAssets(['logo' => $assetPath])
        ->registerAsset('inline-logo', $inline, true);

    expect(fn () => $template->registerUrlAsset(
        'remote-logo',
        'https://assets.example.test/logo.png',
    ))->toThrow(InvalidArgumentException::class);

    config()->set([
        'templates.pdf.remote_assets.enabled' => true,
        'templates.pdf.remote_assets.allowed_hosts' => ['assets.example.test'],
    ]);
    $template->registerUrlAsset('remote-logo', 'https://assets.example.test/logo.png');

    $rendered = $template->render();
    $preview = $template->preview();
    $download = $template->download('contract.pdf');
    $htmlContext = new TemplateContext(
        language: 'en',
        data: ['recipientName' => 'Lin'],
        options: ['reference' => 'CTX-100'],
        fallbackLanguage: 'bg',
        variant: 'standard',
        stickers: [['src' => $assetPath, 'x_mm' => 1.0]],
        frameKey: 'frame',
    );
    $htmlPayload = app(TemplateRenderer::class)
        ->render($template, $htmlContext);
    $defaultHtmlContext = new TemplateContext;
    $contentAccessor = new ContentAccessor($template);
    $assetAccessor = new AssetAccessor($template);

    expect($template->getName())->toBe('Class template')
        ->and($template->getModule())->toBe('Documents')
        ->and($template->getStorageDisk())->toBe('local')
        ->and($template->getStoragePath())->toBe('template-tests')
        ->and($template->getDefaultFilename())->toBe('class-template.pdf')
        ->and($template->hasAsset('logo'))->toBeTrue()
        ->and($template->getAssetFileUrl('logo'))->toBe($assetPath)
        ->and($template->getAssetDataUri('logo'))->toStartWith('data:image/png;base64,')
        ->and($template->getAssetDataUri('inline-logo'))->toBe($inline)
        ->and($template->hasContent('heading'))->toBeTrue()
        ->and($template->getContent('missing', 'fallback'))->toBe('fallback')
        ->and($template->getContentFromNamespace('shared', 'legal'))->toBe('Private')
        ->and($template->getAllContent())->toHaveKey('heading')
        ->and($template->requires())->toBe(['content' => [], 'assets' => []])
        ->and($template->supportsQrCode())->toBeFalse()
        ->and($rendered['html'])->toContain('Grace', 'Statement', 'REF-200')
        ->and($preview->headers->get('content-disposition'))->toContain('inline')
        ->and($download->headers->get('content-disposition'))->toContain('contract.pdf')
        ->and($htmlPayload->html)->toContain('Lin', 'CTX-100')
        ->and($defaultHtmlContext->language)->toBe('en')
        ->and($contentAccessor->get('heading'))->toBe('Statement')
        ->and($contentAccessor->getFrom('shared', 'legal'))->toBe('Private')
        ->and($contentAccessor->offsetExists('heading'))->toBeTrue()
        ->and($contentAccessor->offsetGet('heading'))->toBe('Statement')
        ->and($assetAccessor->has('logo'))->toBeTrue()
        ->and($assetAccessor->get('logo'))->toBe($assetPath)
        ->and($assetAccessor->getFile('logo'))->toBe($assetPath)
        ->and($assetAccessor->fileUrl('logo'))->toBe($assetPath)
        ->and(fn () => $template->generateQrCode())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->useFrame('missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->addStickerByKey('missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->addAssetByKey('missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->addStickerSrc($assetPath, ['x_mm' => INF]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->withDataSchema('Missing\\TemplateData'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->setVariable('bad key', true))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->registerAsset('broken-inline', 'not-a-data-uri', true))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->getAsset('missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->setLanguage('invalid locale!'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $template->setData('not-an-array'))
        ->toThrow(InvalidArgumentException::class);

    $missing = new TestClassPdfTemplate(
        app(Factory::class),
        app(TemplateContentGuard::class),
        app(TemplateAssetGuard::class),
        app(PdfServiceInterface::class),
        $resolver,
    );
    expect($missing->getStoragePath())->toBe('template-tests')
        ->and(fn () => $missing->validate())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $missing->setOption('storage_path', '../bad')->getStoragePath())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $missing->setOption('filename', 42)->getDefaultFilename())
        ->toThrow(InvalidArgumentException::class)
        ->and($missing->setOption('filename', '../confined.pdf')->getDefaultFilename())
        ->toBe('confined.pdf')
        ->and(fn () => $contentAccessor->offsetSet('heading', 'Changed'))
        ->toThrow(LogicException::class)
        ->and(fn () => $contentAccessor->offsetUnset('heading'))
        ->toThrow(LogicException::class);

    File::deleteDirectory(dirname($assetPath));
});

it('validates renderer definition owner and direct template registry boundaries', function (): void {
    $renderers = app(TemplateRendererRegistry::class);
    $definitions = app(TemplateDefinitionRegistry::class);
    $owners = app(TemplateOwnerRegistry::class);
    $owner = TestTemplateOwner::query()->create(['name' => 'Ada']);
    $definition = new TemplateDefinitionData(
        key: 'aaa-contract',
        renderer: 'test',
        view: 'template-tests::core',
        profiles: ['default', 'email'],
        schema: ['name' => ['type' => 'string']],
        subjectPath: 'body.subject',
        requiredRegions: ['main'],
        allowedContentDefinitions: ['template-copy'],
    );
    $definitions->register($definition);

    expect($renderers->has('test'))->toBeTrue()
        ->and($renderers->get('test'))->toBeInstanceOf(TestTemplateRenderer::class)
        ->and($renderers->all())->toHaveKeys(['blade', 'pdf', 'test'])
        ->and($definitions->get('aaa-contract'))->toBe($definition)
        ->and(array_key_first($definitions->all()))->toBe('aaa-contract')
        ->and($owners->has('member'))->toBeTrue()
        ->and($owners->aliases())->toBe(['member'])
        ->and($owners->resolve('member', $owner->id)->is($owner))->toBeTrue()
        ->and(fn () => $renderers->register('Bad Alias', TestTemplateRenderer::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderers->register('test', TestTemplateRenderer::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderers->register('invalid', stdClass::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderers->get('missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $definitions->register($definition))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $definitions->get('missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $owners->register('Bad Alias', TestTemplateOwnerResolver::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $owners->register('member', TestTemplateOwnerResolver::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $owners->register('invalid', stdClass::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $owners->resolve('missing', $owner->id))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $owners->resolve('member', (string) str()->uuid()))
        ->toThrow(InvalidArgumentException::class);

    $invalidDefinitions = [
        new TemplateDefinitionData('Bad Key', 'test', 'template-tests::core'),
        new TemplateDefinitionData('bad-renderer-alias', 'Bad Alias', 'template-tests::core'),
        new TemplateDefinitionData('missing-renderer', 'missing', 'template-tests::core'),
        new TemplateDefinitionData('bad-view', 'test', '../unsafe'),
        new TemplateDefinitionData('empty-profiles', 'test', 'template-tests::core', []),
        new TemplateDefinitionData('duplicate-profiles', 'test', 'template-tests::core', ['web', 'web']),
        new TemplateDefinitionData('bad-profile', 'test', 'template-tests::core', ['Bad Alias']),
        new TemplateDefinitionData(
            'bad-subject',
            'test',
            'template-tests::core',
            subjectPath: 'bad path',
        ),
        new TemplateDefinitionData(
            'duplicate-regions',
            'test',
            'template-tests::core',
            requiredRegions: ['main', 'main'],
        ),
        new TemplateDefinitionData(
            'bad-content-alias',
            'test',
            'template-tests::core',
            allowedContentDefinitions: ['Bad Alias'],
        ),
    ];

    foreach ($invalidDefinitions as $invalidDefinition) {
        expect(fn () => $definitions->register($invalidDefinition))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => new RenderableTemplate('Bad Key'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RenderableTemplate('valid', '../unsafe'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RenderableTemplate('valid', data: ['list']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RenderableTemplate('valid', schema: ['list']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RenderableTemplate('valid', settings: ['list']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects dishonest or unsafe renderer output facts', function (): void {
    $context = new TemplateRenderContext(
        template: new RenderableTemplate('guard-contract'),
        view: 'template-tests::core',
        renderer: 'test',
        locale: 'en',
        subject: null,
        filename: null,
        pdf: null,
        rendererOptions: [],
    );
    $guard = app(TemplateOutputGuard::class);
    $result = static fn (
        string $content = 'safe',
        string $mime = 'text/plain',
        string $renderer = 'test',
        ?int $size = null,
        ?string $checksum = null,
        ?string $subject = null,
        ?string $filename = null,
    ): RenderedTemplateData => new RenderedTemplateData(
        content: $content,
        mimeType: $mime,
        renderer: $renderer,
        byteSize: $size ?? strlen($content),
        checksum: $checksum ?? hash('sha256', $content),
        subject: $subject,
        suggestedFilename: $filename,
    );

    $guard->validate($context, $result(filename: 'safe.txt', subject: 'Subject'));
    $guard->validateFilename('safe-file.pdf');

    expect($context->composition())->toBeNull()
        ->and($context->data())->toBe([])
        ->and($context->settings())->toBe([])
        ->and($context->blocks())->toBe([])
        ->and($context->regions())->toBe([])
        ->and($context->viewData())->toHaveKeys([
            'template',
            'options',
            'composition',
            'blocks',
            'regions',
            'data',
            'settings',
        ])
        ->and(fn () => $guard->validate($context, $result(size: 99)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->validate($context, $result(checksum: str_repeat('0', 64))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->validate($context, $result(renderer: 'pdf')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->validate($context, $result(mime: 'invalid mime')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->validate($context, $result(
            content: 'not-a-pdf',
            mime: 'application/pdf',
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->validate($context, $result(filename: '../unsafe.pdf')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->validate($context, $result(subject: "bad\nsubject")))
        ->toThrow(InvalidArgumentException::class);

    config()->set('templates.limits.output_bytes', 2);
    expect(fn () => $guard->validate($context, $result(content: 'large')))
        ->toThrow(InvalidArgumentException::class);
});

it('fails closed across stored core PDF content and filesystem options', function (): void {
    $storedOptions = app(StoredTemplateOptionsFactory::class);
    $stored = $storedOptions->make('pdf', 'en', 'Statement', [
        'page_size' => 'A5',
        'orientation' => 'landscape',
        'margins' => [
            'left' => 10,
            'right' => 11.5,
            'top' => 12,
            'bottom' => 13,
            'header' => 4,
            'footer' => 5,
        ],
        'default_font' => 'dejavusans',
        'default_font_size' => 11.5,
        'dpi' => 144,
        'image_dpi' => 150,
        'image_quality' => 90,
        'title' => 'Statement',
        'author' => 'NVL',
        'creator' => 'Templates',
        'subject' => 'Account',
        'keywords' => 'account',
        'header_view' => 'nvl-templates::pdf.header',
        'header_data' => ['header' => 'Private'],
        'footer_view' => 'nvl-templates::pdf.footer',
        'footer_data' => ['footer' => 'Page'],
        'watermark' => 'DRAFT',
        'watermark_opacity' => 0.2,
        'compress' => true,
        'pdfa' => false,
        'pdfa_auto' => false,
        'filename' => 'statement.pdf',
    ]);
    $blade = $storedOptions->make('blade', 'en', null, ['filename' => 'message.html']);

    expect($stored->pdf?->pageSize?->value)->toBe('A5')
        ->and($stored->pdf?->orientation?->value)->toBe('landscape')
        ->and($stored->filename)->toBe('statement.pdf')
        ->and($blade->renderer)->toBe('blade')
        ->and($blade->filename)->toBe('message.html');

    $invalidStoredOptions = [
        ['unknown' => true],
        ['page_size' => 42],
        ['page_size' => 'A0'],
        ['orientation' => 42],
        ['orientation' => 'diagonal'],
        ['margins' => ['list']],
        ['margins' => ['unknown' => 1]],
        ['margins' => ['left' => INF]],
        ['filename' => 42],
        ['dpi' => '96'],
        ['watermark_opacity' => INF],
        ['compress' => 'yes'],
        ['header_data' => ['list']],
    ];

    foreach ($invalidStoredOptions as $configured) {
        expect(fn () => $storedOptions->make('pdf', 'en', null, $configured))
            ->toThrow(InvalidArgumentException::class);
    }

    $coreResolver = app(TemplateOptionsResolver::class);
    $coreResolver->resolve(new RenderableTemplate(
        key: 'core-options',
        view: 'template-tests::core',
        options: new TemplateOptions(
            renderer: 'blade',
            locale: 'en',
            subject: 'Subject',
            filename: 'safe.html',
        ),
    ));
    $invalidCoreOptions = [
        new TemplateOptions(renderer: 'Bad Alias'),
        new TemplateOptions(subject: "bad\nsubject"),
        new TemplateOptions(filename: '../unsafe.html'),
    ];

    foreach ($invalidCoreOptions as $options) {
        expect(fn () => $coreResolver->resolve(new RenderableTemplate(
            key: 'invalid-core-options',
            view: 'template-tests::core',
            options: $options,
        )))->toThrow(InvalidArgumentException::class);
    }

    $pdfResolver = app(PdfOptionsResolver::class);
    $pdfContext = static fn (TypedPdfOptions $pdf): TemplateRenderContext => new TemplateRenderContext(
        template: new RenderableTemplate('pdf-options'),
        view: 'nvl-templates::pdf.document',
        renderer: 'pdf',
        locale: 'en',
        subject: null,
        filename: null,
        pdf: $pdf,
        rendererOptions: [],
    );
    $pdfResolver->resolve($pdfContext(new TypedPdfOptions(
        headerView: 'nvl-templates::pdf.header',
        footerView: 'nvl-templates::pdf.footer',
        protectionPermissions: ['print', 'print'],
    )));
    $invalidPdfOptions = [
        new TypedPdfOptions(defaultFont: 'bad font'),
        new TypedPdfOptions(defaultFontSize: 1),
        new TypedPdfOptions(dpi: 1),
        new TypedPdfOptions(imageDpi: 1),
        new TypedPdfOptions(imageQuality: 0),
        new TypedPdfOptions(headerView: 'missing::view'),
        new TypedPdfOptions(pdfa: false, pdfaAuto: true),
        new TypedPdfOptions(showImageErrors: true),
        new TypedPdfOptions(watermarkOpacity: 2),
        new TypedPdfOptions(protectionPermissions: ['execute']),
        new TypedPdfOptions(title: "bad\0title"),
    ];

    foreach ($invalidPdfOptions as $options) {
        expect(fn () => $pdfResolver->resolve($pdfContext($options)))
            ->toThrow(InvalidArgumentException::class);
    }

    $pdfDefaults = config('templates.pdf.defaults');
    $invalidPdfDefaults = [
        'invalid',
        ['unknown' => true],
        ['page_size' => 42],
        ['page_size' => 'A0'],
        ['orientation' => 42],
        ['orientation' => 'diagonal'],
        ['margins' => 'invalid'],
        ['margins' => ['unknown' => 1]],
    ];

    foreach ($invalidPdfDefaults as $defaults) {
        config()->set('templates.pdf.defaults', $defaults);
        expect(fn () => $pdfResolver->resolve($pdfContext(new TypedPdfOptions)))
            ->toThrow(InvalidArgumentException::class);
    }
    config()->set('templates.pdf.defaults', $pdfDefaults);

    $contentGuard = app(TemplateContentGuard::class);
    expect($contentGuard->schema(['type' => 'object']))->toBe(['type' => 'object'])
        ->and($contentGuard->metadata(['source' => 'consumer']))->toBe([
            'source' => 'consumer',
        ])
        ->and(fn () => $contentGuard->metadata(['invalid' => INF]))
        ->toThrow(InvalidArgumentException::class);

    config()->set([
        'templates.limits.settings_depth' => 1,
        'templates.limits.renderer_options_items' => 1,
        'templates.limits.metadata_bytes' => 2,
    ]);
    expect(fn () => $contentGuard->settings(['nested' => ['too' => 'deep']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $contentGuard->rendererOptions(['one' => 1, 'two' => 2]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $contentGuard->metadata(['large' => 'value']))
        ->toThrow(InvalidArgumentException::class);

    $paths = app(SafeFilesystemPathResolver::class);
    $root = storage_path('framework/testing/template-paths');
    File::ensureDirectoryExists($root);
    $createdDirectory = $paths->directory(
        $root.'/nested',
        [$root],
        create: true,
        writable: true,
    );
    $file = $paths->file(
        $createdDirectory.'/report',
        [$root],
        requiredExtension: 'pdf',
        createParent: true,
    );
    File::ensureDirectoryExists($root.'/directory.pdf');

    expect($createdDirectory)->toEndWith('/nested')
        ->and($file)->toEndWith('/report.pdf')
        ->and(fn () => $paths->directory('relative', [$root]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $paths->directory($root, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $paths->directory($root, ['relative']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $paths->directory(storage_path('outside'), [$root]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $paths->directory($root, [$root.'/missing-root']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $paths->file($root.'/directory.pdf', [$root]))
        ->toThrow(InvalidArgumentException::class);

    File::deleteDirectory($root);
});

it('executes maintenance commands in text JSON guarded and repeatable modes', function (): void {
    Queue::fake();

    $this->artisan('nvl:templates:renders:recover')
        ->assertSuccessful()
        ->expectsOutput('Recovered 0 stale template renders.');
    $this->artisan('nvl:templates:renders:recover', ['--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"recovered": 0');
    $this->artisan('nvl:templates:sync', ['--dry-run' => true])
        ->assertSuccessful();
    $this->artisan('nvl:templates:doctor', [
        '--scope' => 'core',
        '--format' => 'text',
        '--strict' => true,
    ])->assertSuccessful()->expectsOutputToContain('renderer.default');
    $this->artisan('nvl:templates:doctor', [
        '--scope' => 'database',
        '--format' => 'text',
    ])->assertSuccessful()->expectsOutputToContain('table.templates');

    expect(fn () => $this->artisan('nvl:templates:renders:recover', [
        '--format' => 'yaml',
    ])->run())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->artisan('nvl:templates:sync', [
            '--format' => 'yaml',
        ])->run())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->artisan('nvl:templates:doctor', [
            '--format' => 'yaml',
        ])->run())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->artisan('nvl:templates:doctor', [
            '--scope' => 'unknown',
        ])->run())->toThrow(InvalidArgumentException::class);

    $root = storage_path('framework/testing/template-view-publish');
    File::ensureDirectoryExists($root);
    config()->set([
        'templates.views.publish_path' => $root.'/views',
        'templates.views.allowed_publish_roots' => [$root],
    ]);

    try {
        $this->artisan('nvl:templates:views:publish')
            ->assertSuccessful()
            ->expectsOutputToContain('Published');
        $this->artisan('nvl:templates:views:publish')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped existing');
        $this->artisan('nvl:templates:views:publish', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Published');

        config()->set('templates.views.allowed_publish_roots', []);
        expect(fn () => $this->artisan('nvl:templates:views:publish')->run())
            ->toThrow(InvalidArgumentException::class);
    } finally {
        File::deleteDirectory($root);
    }
});
