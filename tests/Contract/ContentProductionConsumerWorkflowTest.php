<?php

declare(strict_types=1);

it('defines the complete Content production consumer fixture', function (): void {
    $root = dirname(__DIR__, 2);
    $fixtureRoot = $root.'/tools/fixtures/content-production-consumer';

    foreach ([
        'app/Console/Commands/ContentConsumerSmokeCommand.php',
        'app/Content/Authorization/ContentConsumerAccess.php',
        'app/Content/Authorization/ContentConsumerContentAuthorization.php',
        'app/Content/Authorization/ContentConsumerMediaAuthorization.php',
        'app/Content/Authorization/ContentConsumerMetafieldAuthorization.php',
        'app/Content/Authorization/ContentConsumerPageAuthorization.php',
        'app/Content/Authorization/ContentConsumerSeoAuthorization.php',
        'app/Content/Authorization/ContentConsumerTranslationsAuthorization.php',
        'app/Content/ContentConsumerProbe.php',
        'app/Content/Media/ContentConsumerMediaScanner.php',
        'app/Models/Article.php',
        'app/Pages/ArticlePageResourceHandler.php',
        'app/Providers/ContentConsumerServiceProvider.php',
        'bootstrap/providers.php',
        'config/content.php',
        'config/filesystems.php',
        'config/media.php',
        'config/metafields.php',
        'config/nvl-suite.php',
        'config/pages.php',
        'config/seo.php',
        'config/translatable.php',
        'config/translations.php',
        'database/migrations/2026_08_29_000000_create_articles_table.php',
        'lang/en/consumer.php',
        'typescript/content-consumer.ts',
        'typescript/tsconfig.json',
    ] as $path) {
        expect($fixtureRoot.'/'.$path)->toBeFile();
    }

    /** @var array{modules: array<string, bool>} $suite */
    $suite = require $fixtureRoot.'/config/nvl-suite.php';

    expect($suite['modules'])->toBe([
        'support' => true,
        'data' => true,
        'filterable' => true,
        'translatable' => true,
        'activity' => false,
        'auth' => false,
        'csv' => false,
        'mail-notifications' => false,
        'media' => true,
        'comments' => false,
        'content' => true,
        'metafields' => true,
        'primitives' => false,
        'seo' => true,
        'settings' => false,
        'taxonomy' => false,
        'templates' => false,
        'translations' => true,
        'forms' => false,
        'pages' => true,
    ]);

    /** @var array{scripts: array{analyse: string|list<string>, format: string, format:test: string}} $composer */
    $composer = json_decode(
        contentProductionFixtureContents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $analysis = is_array($composer['scripts']['analyse'])
        ? implode("\n", $composer['scripts']['analyse'])
        : $composer['scripts']['analyse'];

    expect($analysis)->toContain('tools/fixtures/content-production-consumer/app')
        ->and($composer['scripts']['format'])->toContain(
            'tools/fixtures/content-production-consumer',
        )
        ->and($composer['scripts']['format:test'])->toContain(
            'tools/fixtures/content-production-consumer',
        );
});

it('uses package Actions, stable aliases, and explicit authorization', function (): void {
    $fixtureRoot = dirname(__DIR__, 2).'/tools/fixtures/content-production-consumer';
    $provider = contentProductionFixtureContents(
        $fixtureRoot.'/app/Providers/ContentConsumerServiceProvider.php',
    );
    $access = implode("\n", array_map(
        static fn (string $path): string => contentProductionFixtureContents($path),
        glob($fixtureRoot.'/app/Content/Authorization/*.php') ?: [],
    ));
    $article = contentProductionFixtureContents($fixtureRoot.'/app/Models/Article.php');
    $handler = contentProductionFixtureContents(
        $fixtureRoot.'/app/Pages/ArticlePageResourceHandler.php',
    );
    $probe = contentProductionFixtureContents(
        $fixtureRoot.'/app/Content/ContentConsumerProbe.php',
    );
    $configuration = implode("\n", array_map(
        static fn (string $name): string => contentProductionFixtureContents(
            $fixtureRoot.'/config/'.$name.'.php',
        ),
        ['content', 'filesystems', 'media', 'metafields', 'pages', 'seo', 'translatable', 'translations'],
    ));

    expect($provider)->toContain(
        'ContentAuthorization::class',
        'MediaAuthorization::class',
        'MetafieldAuthorization::class',
        'MetafieldReferenceAuthorization::class',
        'PageAuthorization::class',
        'SeoAuthorization::class',
        'TranslationsAuthorization::class',
        'ContentDefinitionRegistry',
        'ContentDefinitionSource',
    )
        ->and($access)->toContain(
            'AuthorizationException',
            'ContentActorData::system()',
            'MediaActorData::system()',
            'PageActorData::system()',
        )
        ->and($article)->toContain(
            'implements HasMedia',
            'InteractsWithMedia',
            "addMediaSlot('document')",
            'oneToOne()',
            "acceptsMimeTypes(['application/pdf'])",
        )
        ->and($handler)->toContain(
            'extends AbstractPageResourceHandler',
            "return 'articles.detail';",
            "return '{slug}';",
            "where('is_published', true)",
            'PageResourceData',
        )
        ->and($configuration)->toContain(
            "'locales' => ['en', 'bg']",
            "'article' => Article::class",
            "'articles.detail' => ArticlePageResourceHandler::class",
            "'disk' => 'local'",
        )
        ->and($probe)->not->toContain(
            'Page::query(',
            'ContentBlock::query(',
            'ContentPlacement::query(',
            'Media::query(',
            'SeoProfile::query(',
            'Metafield::query(',
            'TranslationEntry::query(',
        )
        ->and($probe)->toContain(
            'CreatePageAction',
            'ResolvePageAction',
            'GetNavigationAction',
            'ListPublicChildPagesAction',
            'GetPagePublicationProjectionAction',
            'GetPageEditorBootstrapAction',
            'CreateContentBlockAction',
            'PublishContentBlockAction',
            'PlaceContentBlockAction',
            'ReorderContentPlacementsAction',
            'ReplaceContentPlacementAction',
            'CreateMetafieldDefinitionAction',
            'SetMetafieldAction',
            'ListAuthorizedOwnerMetafieldsAction',
            'SyncSeoProfileAction',
            'GetOwnerSeoProfileAction',
            'UploadMediaAction',
            'FinalizeMediaScanAction',
            'ReplaceOwnerMediaSlotAction',
            'CopyOwnerMediaSlotAction',
            'GetOwnerMediaSlotAction',
            'ClearOwnerMediaSlotAction',
            'ScanTranslationsAction',
            'ImportTranslationsAction',
            'UpdateTranslationEntryAction',
            'ExportTranslationsAction',
            'assertDeniedActor',
            'titleLocale',
            'twentyFiveQueryCount',
            '$editor->toArray();',
        );
});

it('compiles generated Content transport contracts in strict TypeScript', function (): void {
    $fixtureRoot = dirname(__DIR__, 2).'/tools/fixtures/content-production-consumer';
    $typescript = contentProductionFixtureContents(
        $fixtureRoot.'/typescript/content-consumer.ts',
    );
    /** @var array{compilerOptions: array<string, mixed>, include: list<string>} $configuration */
    $configuration = json_decode(
        contentProductionFixtureContents($fixtureRoot.'/typescript/tsconfig.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($typescript)->toContain(
        'Nvl.Pages.Data.PageEditorBootstrapData',
        'Nvl.Content.Data.ContentEditorData',
        'Nvl.Media.Data.Display.MediaLibraryItem',
        'Nvl.Seo.Data.SeoProfileData',
        'Nvl.Metafields.Data.OwnerMetafieldValue',
        'Nvl.Translations.Data.TranslationEntryPayload',
    )
        ->and($configuration['compilerOptions']['strict'] ?? null)->toBeTrue()
        ->and($configuration['compilerOptions']['skipLibCheck'] ?? null)->toBeFalse()
        ->and($configuration['include'])->toContain(
            '../resources/js/types/**/*.d.ts',
            './content-consumer.ts',
        );
});

it('runs both migration ownership modes from a sealed artifact', function (): void {
    $root = dirname(__DIR__, 2);
    $runnerPath = $root.'/tools/run-content-production-consumer.sh';

    expect($runnerPath)->toBeFile()
        ->and(is_executable($runnerPath))->toBeTrue();

    $runner = contentProductionFixtureContents($runnerPath);
    $skillsPosition = mb_strpos(
        $runner,
        'content_consumer_artisan nvl:suite:skills:publish --format=json',
    );
    $typesPosition = mb_strpos(
        $runner,
        'content_consumer_artisan nvl:data:types:check',
    );
    $auditPosition = mb_strpos(
        $runner,
        'content_consumer_artisan nvl:suite:consumer-audit --strict --format=json',
    );
    $smokePosition = mb_strpos(
        $runner,
        'content_consumer_artisan content-consumer:smoke --format=json',
    );
    $rollbackPosition = mb_strpos(
        $runner,
        'content_consumer_artisan migrate:rollback --force --step=999',
    );

    expect($runner)->toContain(
        'set -euo pipefail',
        'mktemp -d',
        'composer archive',
        'create-project',
        '--dry-run',
        '"symlink":false',
        'test ! -L vendor/nvl/laravel-suite',
        'package_owned',
        'application_owned',
        'vendor:publish --tag=content-migrations',
        'vendor:publish --tag=media-migrations',
        'vendor:publish --tag=metafields-migrations',
        'vendor:publish --tag=pages-migrations',
        'vendor:publish --tag=seo-migrations',
        'vendor:publish --tag=translations-migrations',
        'CONTENT_CONSUMER_PACKAGE_MIGRATIONS',
        'FILESYSTEM_DISK=local',
        'MEDIA_FILESYSTEM_DISK=local',
        'MEDIA_QUEUE_CONNECTION=database',
        'QUEUE_CONNECTION=database',
        'queue:work --stop-when-empty',
        'content_consumer_artisan config:cache',
        'content_consumer_artisan route:cache',
        'content_consumer_artisan nvl:suite:skills:publish --format=json',
        'content_consumer_artisan nvl:data:types:generate',
        'content_consumer_artisan nvl:data:types:check',
        'content_consumer_artisan nvl:suite:doctor --strict --production --format=json',
        'content_consumer_artisan nvl:suite:consumer-audit --strict --format=json',
        'content_consumer_artisan nvl:media:owner-slots:prune',
        'content_consumer_artisan content-consumer:smoke --format=json',
        'content_consumer_artisan content-consumer:smoke --verify-queue --format=json',
        'content-consumer-document-path',
        'content-consumer-cover-path',
        'test -f "$document_absolute_path"',
        'test ! -e "$cover_absolute_path"',
        'rm -- "$document_absolute_path"',
        './node_modules/.bin/tsc --noEmit -p content-consumer-types/tsconfig.json',
    )
        ->and($runner)->not->toContain(
            '"symlink":true',
            'QUEUE_CONNECTION=sync',
            '--ignore-platform-reqs',
            'consumer-audit-ignore',
        );

    expect($skillsPosition)->toBeInt()
        ->and($typesPosition)->toBeInt()
        ->and($auditPosition)->toBeInt()
        ->and($smokePosition)->toBeInt()
        ->and($rollbackPosition)->toBeInt()
        ->and($skillsPosition)->toBeLessThan($auditPosition)
        ->and($typesPosition)->toBeLessThan($auditPosition)
        ->and($auditPosition)->toBeLessThan($smokePosition);

    if (! is_int($rollbackPosition)) {
        return;
    }

    expect(mb_substr($runner, 0, $rollbackPosition))->toContain(
        'test -f "$document_absolute_path"',
    )->and(mb_substr($runner, $rollbackPosition))->toContain(
        'test -f "$document_absolute_path"',
        'test ! -e "$cover_absolute_path"',
        'rm -- "$document_absolute_path"',
    );
});

function contentProductionFixtureContents(string $path): string
{
    $contents = file_get_contents($path);

    expect($contents)->toBeString();

    return $contents;
}
