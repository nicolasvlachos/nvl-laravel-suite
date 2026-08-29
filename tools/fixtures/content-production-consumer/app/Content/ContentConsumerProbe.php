<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Authorization\ContentConsumerAccess;
use App\Models\Article;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\GetOwnerContentEditorAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\ReorderContentPlacementsAction;
use Nvl\Content\Actions\ReplaceContentPlacementAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\ReorderContentPlacementData;
use Nvl\Content\Data\Mutations\ReorderContentPlacementsData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Media\Actions\ClearOwnerMediaSlotAction;
use Nvl\Media\Actions\CopyOwnerMediaSlotAction;
use Nvl\Media\Actions\FinalizeMediaScanAction;
use Nvl\Media\Actions\GetOwnerMediaSlotAction;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Actions\GetNavigationAction;
use Nvl\Pages\Actions\GetPageEditorBootstrapAction;
use Nvl\Pages\Actions\GetPagePublicationProjectionAction;
use Nvl\Pages\Actions\ListPublicChildPagesAction;
use Nvl\Pages\Actions\ResolvePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Data\PageRequestContextData;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Models\Page;
use Nvl\Seo\Actions\GetOwnerSeoProfileAction;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Actions\Sync\ScanTranslationsAction;
use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Models\TranslationEntry;
use RuntimeException;

/** Orchestrates the real consumer workflow exclusively through package boundaries. */
final readonly class ContentConsumerProbe
{
    public function __construct(
        private ContentConsumerAccess $access,
        private SyncContentDefinitionsAction $syncDefinitions,
        private CreateContentBlockAction $createBlock,
        private PublishContentBlockAction $publishBlock,
        private PlaceContentBlockAction $placeBlock,
        private ReplaceContentPlacementAction $replacePlacement,
        private ReorderContentPlacementsAction $reorderPlacements,
        private GetOwnerContentEditorAction $getContentEditor,
        private CreatePageAction $createPage,
        private GetPageEditorBootstrapAction $getPageEditor,
        private GetNavigationAction $getNavigation,
        private ListPublicChildPagesAction $listChildren,
        private GetPagePublicationProjectionAction $getPublication,
        private ResolvePageAction $resolvePage,
        private CreateMetafieldDefinitionAction $createMetafieldDefinition,
        private SetMetafieldAction $setMetafield,
        private ListAuthorizedOwnerMetafieldsAction $listMetafields,
        private SyncSeoProfileAction $syncSeo,
        private GetOwnerSeoProfileAction $getSeo,
        private UploadMediaAction $uploadMedia,
        private FinalizeMediaScanAction $finalizeMedia,
        private ReplaceOwnerMediaSlotAction $replaceMediaSlot,
        private CopyOwnerMediaSlotAction $copyMediaSlot,
        private GetOwnerMediaSlotAction $getMediaSlot,
        private ClearOwnerMediaSlotAction $clearMediaSlot,
        private MediaLibraryContract $mediaLibrary,
        private ScanTranslationsAction $scanTranslations,
        private ImportTranslationsAction $importTranslations,
        private ListTranslationEntriesAction $listTranslations,
        private GetTranslationCatalogStatisticsAction $translationStatistics,
        private UpdateTranslationEntryAction $updateTranslation,
        private ExportTranslationsAction $exportTranslations,
    ) {}

    /** @return array<string, int|string|bool> */
    public function run(): array
    {
        $pageActor = PageActorData::system();
        $contentActor = ContentActorData::system();
        $mediaActor = MediaActorData::system();
        $this->syncDefinitions->execute($contentActor);

        $article = Article::query()->create([
            'slug' => 'proof-article',
            'title' => 'Proof article',
            'is_published' => true,
        ]);
        $copyTarget = Article::query()->create([
            'slug' => 'proof-copy',
            'title' => 'Proof copy',
            'is_published' => false,
        ]);

        $home = $this->createBilingualPage('pages.home', 'home', $pageActor);
        $child = $this->createBilingualPage(
            'pages.home.child',
            'child',
            $pageActor,
            $home->id,
        );
        $fallback = $this->createPage->execute(
            new CreatePageData(
                key: 'pages.fallback',
                slug: 'fallback',
                status: PageStatus::Published,
                isNavigable: false,
                translations: ['en' => ['title' => 'Fallback title']],
            ),
            $pageActor,
        );
        $resourcePage = $this->createPage->execute(
            new CreatePageData(
                key: 'pages.articles',
                slug: 'articles',
                kind: PageKind::Resource,
                resource: 'articles.detail',
                status: PageStatus::Published,
                isNavigable: false,
                translations: [
                    'en' => ['title' => 'Articles'],
                    'bg' => ['title' => 'Статии'],
                ],
            ),
            $pageActor,
        );

        [$firstBlock, $replacementBlock] = $this->createPublishedBlocks($contentActor);
        $firstPlacement = $this->placeBlock->execute(
            $firstBlock,
            $home,
            Page::CONTENT_GROUP,
            new PlaceContentBlockData(key: 'lead', sortOrder: 20),
            $contentActor,
        );
        $secondPlacement = $this->placeBlock->execute(
            $replacementBlock,
            $home,
            Page::CONTENT_GROUP,
            new PlaceContentBlockData(
                key: 'supporting',
                region: 'sidebar',
                sortOrder: 10,
            ),
            $contentActor,
        );
        $replaced = $this->replacePlacement->execute(
            $home,
            Page::CONTENT_GROUP,
            $firstPlacement->id,
            $replacementBlock->id,
            $firstPlacement->revision,
            $contentActor,
        );
        $reordered = $this->reorderPlacements->execute(
            $home,
            Page::CONTENT_GROUP,
            new ReorderContentPlacementsData([
                new ReorderContentPlacementData(
                    $replaced->id,
                    $replaced->revision,
                    'main',
                    null,
                    10,
                ),
                new ReorderContentPlacementData(
                    $secondPlacement->id,
                    $secondPlacement->revision,
                    'main',
                    null,
                    20,
                ),
            ]),
            $contentActor,
        );

        $this->configureLocalizedMetadata($home);
        $editor = $this->getPageEditor->execute($home->id, 'bg', $pageActor);
        $navigation = $this->getNavigation->execute(
            'default',
            'bg',
            PageActorData::anonymous(),
        );
        $children = $this->listChildren->execute(
            $home->id,
            new PageRequestContextData('default', 'bg'),
        );
        $publication = $this->getPublication->execute(
            $home->id,
            'bg',
            PageActorData::anonymous(),
        );
        $fallbackProjection = $this->resolvePage->execute(
            $fallback->path,
            'default',
            'bg',
            PageActorData::anonymous(),
        );
        $resourceProjection = $this->resolvePage->execute(
            $resourcePage->path.'/'.$article->slug,
            'default',
            'bg',
            PageActorData::anonymous(),
        );

        $this->ensure($editor->content->placements !== [], 'Page editor omitted Content.');
        $this->ensure($editor->seo !== null, 'Page editor omitted SEO.');
        $this->ensure($editor->metafields !== [], 'Page editor omitted Metafields.');
        $this->ensure($navigation->items !== [], 'Navigation was empty.');
        $this->ensure($children->sole()->id === $child->id, 'Public child projection failed.');
        $this->ensure($publication->content->blocks !== [], 'Publication omitted Content.');
        $this->ensure(
            $fallbackProjection->page->titleLocale === 'en',
            'Locale fallback provenance was not preserved.',
        );
        $this->ensure(
            $resourceProjection->resource?->id === $article->id,
            'Dynamic Article resource did not resolve.',
        );
        $fallbackLocale = $fallbackProjection->page->titleLocale;
        $resource = $resourceProjection->resource;

        if ($fallbackLocale === null || $resource === null) {
            throw new RuntimeException('Page locale or resource provenance is incomplete.');
        }

        [$oneQueryCount, $twentyFiveQueryCount] = $this->measureEditorQueries(
            $replacementBlock,
            $pageActor,
            $contentActor,
        );
        $this->ensure(
            $oneQueryCount === $twentyFiveQueryCount,
            'Editor query count grew with placement cardinality.',
        );

        $media = $this->exerciseMedia($article, $copyTarget, $mediaActor);
        $translation = $this->exerciseTranslations();
        $denials = $this->assertDeniedActor($home, $article);

        return [
            'pages' => 5,
            'placements' => count($reordered->placements),
            'editor_query_count' => $oneQueryCount,
            'editor_query_count_25' => $twentyFiveQueryCount,
            'fallback_locale' => $fallbackLocale,
            'resource' => $resource->type,
            'document_media_id' => $media->id,
            'translation' => $translation,
            'authorization_denials' => $denials,
        ];
    }

    /** @return array<string, int|string|bool> */
    public function verifyQueueAndPrepareRollback(): array
    {
        $article = Article::query()->where('slug', 'proof-article')->firstOrFail();
        $actor = MediaActorData::system();
        $document = $this->getMediaSlot->execute($actor, $article, 'document');
        $cover = $this->getMediaSlot->execute($actor, $article, 'cover');

        if ($document === null || $cover === null) {
            throw new RuntimeException('The queued verification Media slots are incomplete.');
        }

        $coverModel = $this->mediaLibrary->findOrFail($cover->id, includeVariations: true);
        $documentModel = $this->mediaLibrary->findOrFail($document->id, includeVariations: true);
        $documentPath = $documentModel->buildPath();
        $coverPath = $coverModel->buildPath();
        $variationCount = $coverModel->imageVariations->count();

        $this->ensure($variationCount > 0, 'The Media worker generated no cover variations.');
        $this->ensure(Storage::disk('local')->exists($documentPath), 'Document file is missing.');
        $this->ensure(Storage::disk('local')->exists($coverPath), 'Cover file is missing.');

        $this->clearMediaSlot->execute($actor, $article, 'cover', Str::uuid()->toString());

        $this->ensure(Storage::disk('local')->exists($documentPath), 'Document was not preserved.');
        $this->ensure(! Storage::disk('local')->exists($coverPath), 'Cover cleanup failed.');

        return [
            'queued_variations' => $variationCount,
            'document_preserved' => true,
            'cover_removed' => true,
        ];
    }

    private function createBilingualPage(
        string $key,
        string $slug,
        PageActorData $actor,
        ?string $parentId = null,
    ): Page {
        return $this->createPage->execute(
            new CreatePageData(
                key: $key,
                slug: $slug,
                parentId: $parentId,
                status: PageStatus::Published,
                translations: [
                    'en' => ['title' => Str::headline($slug)],
                    'bg' => ['title' => 'Български '.Str::headline($slug)],
                ],
            ),
            $actor,
        );
    }

    /** @return array{ContentBlock, ContentBlock} */
    private function createPublishedBlocks(ContentActorData $actor): array
    {
        $create = function (string $key, string $title) use ($actor): ContentBlock {
            $block = $this->createBlock->execute(
                new CreateContentBlockData(
                    definition: 'consumer.section',
                    key: $key,
                    translations: [
                        'en' => ['title' => $title],
                        'bg' => ['title' => 'Български '.$title],
                    ],
                ),
                $actor,
            );

            return $this->publishBlock->execute($block, $block->revision, $actor);
        };

        return [
            $create('consumer-lead', 'Lead'),
            $create('consumer-replacement', 'Replacement'),
        ];
    }

    private function configureLocalizedMetadata(Page $page): void
    {
        $this->createMetafieldDefinition->execute(
            CreateMetafieldDefinitionPayload::validateAndCreate([
                'namespace' => 'consumer',
                'key' => 'summary',
                'type' => 'text',
                'isTranslatable' => true,
                'assignment' => [
                    'ownerType' => 'page',
                    'section' => 'general',
                ],
                'translations' => [
                    'en' => ['title' => 'Summary'],
                    'bg' => ['title' => 'Резюме'],
                ],
            ]),
        );
        $english = $this->setMetafield->execute(
            $page,
            'consumer.summary',
            'English summary',
            'en',
        );
        $this->setMetafield->execute(
            $page,
            'consumer.summary',
            'Българско резюме',
            'bg',
            $english->revision,
        );
        $fields = $this->listMetafields->execute($page, 'bg');
        $field = $fields->first(
            static fn (OwnerMetafieldField $candidate): bool => $candidate->handle === 'consumer.summary',
        );
        $this->ensure(
            $field instanceof OwnerMetafieldField && $field->value === 'Българско резюме',
            'Localized Metafield projection failed.',
        );

        $this->syncSeo->execute(
            $page,
            SeoProfilePayload::validateAndCreate([
                'translations' => [
                    'en' => [
                        'path' => '/home',
                        'title' => 'Consumer home',
                        'description' => 'English consumer description.',
                    ],
                    'bg' => [
                        'path' => '/bg/home',
                        'title' => 'Начална страница',
                        'description' => 'Българско описание.',
                    ],
                ],
            ]),
            'default',
        );
        $seo = $this->getSeo->execute($page, 'default');
        $this->ensure(
            $seo !== null && ($seo->translations['bg']->title ?? null) === 'Начална страница',
            'Localized SEO projection failed.',
        );
    }

    /** @return array{int, int} */
    private function measureEditorQueries(
        ContentBlock $block,
        PageActorData $pageActor,
        ContentActorData $contentActor,
    ): array {
        $page = $this->createPage->execute(
            new CreatePageData(
                key: 'pages.editor-performance',
                slug: 'editor-performance',
                status: PageStatus::Published,
                isNavigable: false,
                translations: [
                    'en' => ['title' => 'Editor performance'],
                    'bg' => ['title' => 'Редактор производителност'],
                ],
            ),
            $pageActor,
        );
        $this->placeBlock->execute(
            $block,
            $page,
            Page::CONTENT_GROUP,
            new PlaceContentBlockData(key: 'performance-1'),
            $contentActor,
        );
        $oneQueryCount = $this->editorQueryCount($page, $pageActor);

        foreach (range(2, 25) as $index) {
            $this->placeBlock->execute(
                $block,
                $page,
                Page::CONTENT_GROUP,
                new PlaceContentBlockData(
                    key: "performance-{$index}",
                    sortOrder: $index,
                ),
                $contentActor,
            );
        }

        $twentyFiveQueryCount = $this->editorQueryCount($page, $pageActor);

        return [$oneQueryCount, $twentyFiveQueryCount];
    }

    private function editorQueryCount(Page $page, PageActorData $actor): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $editor = $this->getPageEditor->execute($page->id, 'en', $actor);
            $queryCount = count(DB::getQueryLog());
            $editor->toArray();

            $this->ensure(
                count(DB::getQueryLog()) === $queryCount,
                'Editor DTO access triggered lazy queries.',
            );

            return $queryCount;
        } finally {
            DB::disableQueryLog();
        }
    }

    private function exerciseMedia(
        Article $article,
        Article $copyTarget,
        MediaActorData $actor,
    ): Media {
        $document = $this->upload($article, 'document', 'guide.pdf', $this->pdfBytes());
        $replacementKey = Str::uuid()->toString();
        $replaced = $this->replaceMediaSlot->execute(
            $actor,
            $article,
            'document',
            $document->id,
            $replacementKey,
        );
        $replayed = $this->replaceMediaSlot->execute(
            $actor,
            $article,
            'document',
            $document->id,
            $replacementKey,
        );
        $this->ensure($replaced->toArray() === $replayed->toArray(), 'Media replay changed.');

        $conflicting = $this->upload(
            $article,
            'document',
            'conflict.pdf',
            $this->pdfBytes('conflict'),
        );
        $conflictDetected = false;

        try {
            $this->replaceMediaSlot->execute(
                $actor,
                $article,
                'document',
                $conflicting->id,
                $replacementKey,
            );
        } catch (LogicException) {
            $conflictDetected = true;
        }

        $this->ensure($conflictDetected, 'Media idempotency conflict was not rejected.');
        $this->mediaLibrary->delete($conflicting);

        $copy = $this->copyMediaSlot->execute(
            $actor,
            $copyTarget,
            'document',
            $document->id,
            Str::uuid()->toString(),
        );
        $read = $this->getMediaSlot->execute($actor, $copyTarget, 'document');
        $this->ensure($read?->id === $copy->id, 'Copied owner slot could not be read.');
        $this->clearMediaSlot->execute(
            $actor,
            $copyTarget,
            'document',
            Str::uuid()->toString(),
        );
        $this->ensure(
            $this->getMediaSlot->execute($actor, $copyTarget, 'document') === null,
            'Copied owner slot did not clear.',
        );

        $cover = $this->upload($article, 'cover', 'cover.png', $this->pngBytes());
        $this->replaceMediaSlot->execute(
            $actor,
            $article,
            'cover',
            $cover->id,
            Str::uuid()->toString(),
        );

        File::put(
            storage_path('app/content-consumer-document-path'),
            $document->buildPath(),
        );
        File::put(
            storage_path('app/content-consumer-cover-path'),
            $cover->buildPath(),
        );

        return $document;
    }

    private function upload(
        Article $article,
        string $slotName,
        string $filename,
        string $contents,
    ): Media {
        $temporary = tempnam(sys_get_temp_dir(), 'content_consumer_media_');

        if (! is_string($temporary)) {
            throw new RuntimeException('Unable to allocate a temporary Media upload.');
        }

        File::put($temporary, $contents);

        try {
            $slot = $article->getMediaSlot($slotName);

            if (! $slot instanceof MediaSlot) {
                throw new RuntimeException("Media slot [{$slotName}] is not registered.");
            }

            $uploaded = $this->uploadMedia->execute(
                file: new UploadedFile($temporary, $filename, null, null, true),
                disk: 'local',
                model: $article,
                slot: $slot,
                fileName: $filename,
                isPublic: false,
                skipAutoVariations: false,
            );

            return $this->finalizeMedia->execute(
                $uploaded,
                new MediaScanResultData(
                    clean: true,
                    mimeType: (string) $uploaded->mime_type,
                    extension: (string) $uploaded->extension,
                    size: $uploaded->size,
                    checksum: $uploaded->digest,
                    diagnostics: ['scanner' => 'content-consumer'],
                ),
            );
        } finally {
            File::delete($temporary);
        }
    }

    private function exerciseTranslations(): string
    {
        $sourceValue = __('consumer.editor_ready');
        $scan = $this->scanTranslations->execute();
        $import = $this->importTranslations->execute(['app'], 'php');
        $entries = $this->listTranslations->execute(100, FilterSet::none());
        $entry = collect($entries->items())->first(
            static fn (TranslationEntry $candidate): bool => $candidate->scope_name === 'app'
                && $candidate->locale === 'en'
                && $candidate->group === 'consumer'
                && $candidate->key === 'editor_ready',
        );

        if (! $entry instanceof TranslationEntry) {
            throw new RuntimeException('The imported consumer translation entry was not listed.');
        }

        $updated = $this->updateTranslation->execute(
            $entry,
            new UpdateTranslationEntryPayload(
                'The production content editor is ready.',
                $entry->revision,
            ),
        );
        $export = $this->exportTranslations->execute(
            ['app'],
            ['en'],
            'php',
            'generated',
        );
        $exportedPath = storage_path('app/content-consumer-translations/en/consumer.php');

        $this->ensure($sourceValue === 'The content editor is ready.', 'Translation source failed.');
        $this->ensure($scan['hits'] > 0, 'Translation scanner found no consumer key.');
        $this->ensure($import['entries'] > 0, 'Translation import found no entries.');
        $this->ensure($export['files'] > 0, 'Translation export wrote no files.');
        $this->ensure(File::isFile($exportedPath), 'Translation export artifact is missing.');

        return (string) $updated->value;
    }

    private function assertDeniedActor(Page $page, Article $article): int
    {
        $deniedPage = new PageActorData('consumer', 'denied');
        $deniedContent = new ContentActorData('consumer', 'denied');
        $deniedMedia = new MediaActorData('consumer', 'denied');
        $denials = [
            $this->denied(fn (): Page => $this->createPage->execute(
                new CreatePageData(
                    key: 'pages.denied',
                    slug: 'denied',
                    translations: ['en' => ['title' => 'Denied']],
                ),
                $deniedPage,
            )),
            $this->denied(fn (): mixed => $this->getContentEditor->execute(
                $page,
                Page::CONTENT_GROUP,
                $deniedContent,
            )),
            $this->denied(fn (): mixed => $this->getMediaSlot->execute(
                $deniedMedia,
                $article,
                'document',
            )),
            $this->access->denying(
                fn (): bool => $this->denied(fn (): mixed => $this->getSeo->execute($page)),
            ),
            $this->access->denying(
                fn (): bool => $this->denied(
                    fn (): mixed => $this->listMetafields->execute($page, 'en'),
                ),
            ),
            $this->access->denying(
                fn (): bool => $this->denied(
                    fn (): mixed => $this->translationStatistics->execute(FilterSet::none()),
                ),
            ),
        ];

        $this->ensure(! in_array(false, $denials, true), 'A denied actor crossed a boundary.');

        return count($denials);
    }

    private function denied(Closure $callback): bool
    {
        try {
            $callback();
        } catch (AuthorizationException) {
            return true;
        }

        return false;
    }

    private function pdfBytes(string $label = 'proof'): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Label({$label})>>endobj\n%%EOF";
    }

    private function pngBytes(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        if (! is_string($decoded)) {
            throw new RuntimeException('Unable to decode the proof PNG.');
        }

        return $decoded;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
