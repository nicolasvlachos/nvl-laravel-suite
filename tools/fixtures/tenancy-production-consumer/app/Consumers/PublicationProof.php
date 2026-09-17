<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\CommentDocumentData;
use Nvl\Comments\Data\Mutations\CreateRichCommentData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Facades\Content;
use Nvl\Csv\Services\CSVExport;
use Nvl\Forms\Actions\Form\CreateFormAction;
use Nvl\Forms\Actions\FormEntry\CreateFormEntryAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\Media\Models\Media;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Templates\Actions\CreateTemplateAction;
use Nvl\Templates\Actions\CreateTemplateVersionAction;
use Nvl\Templates\Actions\PublishTemplateVersionAction;
use Nvl\Templates\Actions\RenderStoredTemplateAction;
use Nvl\Templates\Actions\SyncTemplateDefinitionsAction;
use Nvl\Templates\Data\Mutations\CreateTemplateData;
use Nvl\Templates\Data\Mutations\CreateTemplateVersionData;
use Nvl\Templates\Data\Mutations\RenderTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Models\TemplateVersion;
use RuntimeException;

/** Publishes and verifies the full tenant-local application graph. */
final readonly class PublicationProof
{
    public function __construct(
        private SyncContentDefinitionsAction $syncContentDefinitions,
        private CreatePageAction $createPage,
        private PageUrlGenerator $pageUrls,
        private CreateMetafieldDefinitionAction $createMetafieldDefinition,
        private SetMetafieldAction $setMetafield,
        private CreateTermAction $createTerm,
        private AttachTermsAction $attachTerms,
        private SyncSeoProfileAction $syncSeo,
        private CreateFormAction $createForm,
        private CreateFormEntryAction $createEntry,
        private SyncTemplateDefinitionsAction $syncTemplates,
        private CreateTemplateAction $createTemplate,
        private CreateTemplateVersionAction $createVersion,
        private PublishTemplateVersionAction $publishVersion,
        private RenderStoredTemplateAction $renderTemplate,
        private CreateRichCommentAction $createComment,
        private ScheduledMailScheduler $mail,
        private SitemapGenerator $sitemaps,
    ) {}

    /** Synchronize code-owned Content and Template vocabulary in platform mode. */
    public function synchronize(): void
    {
        $this->syncContentDefinitions->execute(ContentActorData::system());
        $this->syncTemplates->execute();
    }

    /** @return array<string, scalar> */
    public function seed(string $label, Media $media, User $principal): array
    {
        $actor = PageActorData::system();
        $page = $this->createPage->execute(new CreatePageData(
            key: 'pages.publication',
            slug: 'publication',
            status: PageStatus::Published,
            translations: [
                'en' => ['title' => "Tenant {$label} publication"],
                'bg' => ['title' => "Публикация на наемател {$label}"],
            ],
        ), $actor);
        $block = Content::createBlock(new CreateContentBlockData(
            definition: 'consumer-hero',
            key: 'publication-hero',
            scope: 'site',
            scopeKey: 'default',
            translations: [
                'en' => ['title' => "Tenant {$label} hero"],
                'bg' => ['title' => "Заглавие {$label}"],
            ],
        ), ContentActorData::system());
        $block = Content::publishBlock($block, $block->revision, ContentActorData::system());
        Content::place($block, $page, 'content', new PlaceContentBlockData(
            key: 'hero',
            overrides: ['media' => $media->id],
        ), ContentActorData::system());
        $snapshot = Content::capture($page, 'content', ContentActorData::system(), publishing: true);

        $theme = $this->createMetafieldDefinition->execute(CreateMetafieldDefinitionPayload::from([
            'namespace' => 'page',
            'key' => 'theme',
            'type' => 'string',
            'assignment' => ['ownerType' => 'page', 'section' => 'publication'],
            'translations' => ['en' => ['title' => 'Theme'], 'bg' => ['title' => 'Тема']],
        ]));
        $reference = $this->createMetafieldDefinition->execute(CreateMetafieldDefinitionPayload::from([
            'namespace' => 'page',
            'key' => 'hero_media',
            'type' => 'reference',
            'referencedModelType' => 'media',
            'assignment' => ['ownerType' => 'page', 'section' => 'publication'],
            'translations' => ['en' => ['title' => 'Hero media'], 'bg' => ['title' => 'Основна медия']],
        ]));
        $this->setMetafield->execute($page, $theme->handle, "tenant-{$label}");
        $this->setMetafield->execute($page, $reference->handle, $media->id);
        $term = $this->createTerm->execute(new MutateTermPayload(
            'category',
            'publication',
            ['en' => ['name' => 'Publication'], 'bg' => ['name' => 'Публикация']],
        ));
        $this->attachTerms->execute($page, 'category', [$term]);
        $this->syncSeo->execute($page, SeoProfilePayload::from([
            'translations' => ['en' => ['path' => '/publication', 'title' => "Tenant {$label} publication"]],
        ]));

        $form = $this->createForm->execute(MutateFormPayload::validateForCreate([
            'handle' => 'publication-contact',
            'status' => FormStatus::ACTIVE->value,
            'resolvement' => Resolvement::ENTRIES->value,
            'type' => FormType::IFRAME->value,
            'enableHoneypot' => false,
            'enableRateLimiting' => false,
            'requireCsrf' => false,
            'translations' => ['en' => ['name' => "Tenant {$label} contact"]],
        ]), $principal);
        $entry = $this->createEntry->execute(FormEntryPayload::from([
            'formId' => $form->id,
            'subject' => "Tenant {$label} inquiry",
            'email' => strtolower($label).'@tenancy-consumer.test',
            'submissionData' => ['tenant' => $label],
            'submittedFrom' => 'https://auth-media.tenancy-consumer.test/publication',
        ]), '127.0.0.1', 'tenancy-consumer', "tenant-{$label}", $principal, "publication-entry-{$label}");

        $templateActor = TemplateActorData::system();
        $template = $this->createTemplate->execute(new CreateTemplateData(
            key: 'consumer-publication',
            translations: ['en' => ['title' => 'Publication']],
        ), $templateActor);
        $version = $this->createVersion->execute($template, new CreateTemplateVersionData, $templateActor);
        $templateBlock = Content::createBlock(new CreateContentBlockData(
            definition: 'consumer-template-copy',
            key: 'publication-template-copy',
            translations: ['en' => ['body' => "Rendered tenant {$label}"]],
        ), $templateActor->contentActor());
        $templateBlock = Content::publishBlock($templateBlock, $templateBlock->revision, $templateActor->contentActor());
        Content::place($templateBlock, $version, TemplateVersion::CONTENT_GROUP, new PlaceContentBlockData('body'), $templateActor->contentActor());
        $version = $this->publishVersion->execute($version, $version->revision, $templateActor);
        $render = $this->renderTemplate->execute($template, new RenderTemplateData(
            locale: 'en',
            payload: ['tenant' => $label],
            ownerType: 'page',
            ownerId: $page->id,
            versionId: $version->id,
            idempotencyKey: "publication-render-{$label}",
        ), $templateActor);

        $comment = $this->createComment->execute(
            $page,
            new CreateRichCommentData(new CommentDocumentData(1, [[
                'type' => 'paragraph',
                'children' => [
                    ['type' => 'text', 'text' => "Review tenant {$label} with "],
                    ['type' => 'mention', 'tokenId' => (string) Str::uuid(), 'resource' => 'principal', 'id' => (string) $principal->getKey()],
                ],
            ]])),
            CommentActorData::fromAuthenticatable($principal),
            CommentAudience::Member,
        );
        $activity = ActivityLog::record($page, ActivityEvent::StatusChanged, context: ['channel' => 'website'], actor: $principal, logName: 'publication');
        $scheduled = $this->mail->schedule(new ScheduleMailData(
            factoryAlias: 'consumer.publication',
            payloadVersion: 1,
            payload: ['tenant_label' => $label],
            recipients: new ScheduledRecipients([new Recipient(strtolower($label).'@tenancy-consumer.test')]),
            scheduledFor: CarbonImmutable::now('UTC'),
            metadata: ['page_id' => $page->id],
        ));
        $csv = CSVExport::make()
            ->disk('local')
            ->path("tenants/{$page->tenant_id}/exports")
            ->filename('publication.csv')
            ->headings(['tenant', 'page_id', 'form_entry_id'])
            ->fields(['tenant', 'page_id', 'form_entry_id'])
            ->fromArray([['tenant' => $label, 'page_id' => $page->id, 'form_entry_id' => $entry->id]]);
        $sitemap = $this->sitemaps->generate('default');
        $publicUrl = $this->pageUrls->url($page, 'en');
        $signedUrl = $media->buildPrivateUrl(expiration: now()->addMinutes(5));
        $exportHash = hash_file('sha256', $csv->path);
        if (! is_string($exportHash)) {
            throw new RuntimeException('Unable to hash the tenant export.');
        }

        return [
            'page_id' => $page->id,
            'page_revision' => $page->revision,
            'page_key' => $page->key,
            'page_site' => $page->site,
            'page_path' => '/publication',
            'public_url' => $publicUrl,
            'snapshot_version' => $snapshot->version,
            'media_id' => $media->id,
            'term_id' => $term->id,
            'term_revision' => $term->revision,
            'form_id' => $form->id,
            'form_entry_id' => $entry->id,
            'template_id' => $template->id,
            'render_checksum' => $render->checksum,
            'render_content' => $render->content,
            'comment_id' => $comment->id,
            'comment_revision' => $comment->revision,
            'activity_id' => (string) $activity?->getKey(),
            'scheduled_mail_id' => $scheduled->id,
            'export_path' => $csv->path,
            'export_hash' => $exportHash,
            'sitemap_hash' => hash('sha256', $sitemap),
            'sitemap_contains_path' => str_contains($sitemap, '/publication'),
            'signed_url' => $signedUrl,
        ];
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b @return array<string, bool> */
    public function compare(array $a, array $b): array
    {
        return [
            'publication_keys_match' => $a['page_key'] === $b['page_key'],
            'publication_sites_match' => $a['page_site'] === $b['page_site'],
            'publication_paths_match' => $a['page_path'] === $b['page_path'],
            'publication_ids_are_distinct' => $a['page_id'] !== $b['page_id'] && $a['media_id'] !== $b['media_id'],
            'snapshots_are_tenant_distinct' => $a['snapshot_version'] !== $b['snapshot_version'],
            'render_outputs_are_distinct' => $a['render_checksum'] !== $b['render_checksum'] && $a['render_content'] !== $b['render_content'],
            'exports_are_distinct' => $a['export_hash'] !== $b['export_hash'] && $a['export_path'] !== $b['export_path'],
            'sitemaps_are_scoped' => $a['sitemap_contains_path'] === true && $b['sitemap_contains_path'] === true,
            'public_outputs_are_distinct' => $a['public_url'] !== $b['public_url'] && $a['sitemap_hash'] !== $b['sitemap_hash'],
            'signed_urls_are_distinct' => $a['signed_url'] !== $b['signed_url'],
            'publication_rows_are_tenant_owned' => DB::table('pages')->whereIn('id', [$a['page_id'], $b['page_id']])->distinct()->count('tenant_id') === 2,
            'mail_is_queued_per_tenant' => $a['scheduled_mail_id'] !== $b['scheduled_mail_id'],
        ];
    }
}
