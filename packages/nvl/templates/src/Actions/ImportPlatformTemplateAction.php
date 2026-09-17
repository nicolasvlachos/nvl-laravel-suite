<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Content\Actions\ExportContentSnapshotForCopyAction;
use Nvl\Content\Actions\ImportContentSnapshotAction;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Templates\Data\Mutations\ImportPlatformTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateTenantGrant;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Services\TemplateContentCopyAccess;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Services\CanonicalJson;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Throwable;

/** Copies one granted platform Template, Content graph, and Media set atomically. */
final readonly class ImportPlatformTemplateAction
{
    public function __construct(
        private TemplateContentCopyAccess $access,
        private ExportContentSnapshotForCopyAction $exportContent,
        private ImportContentSnapshotAction $importContent,
        private MediaCatalogImport $media,
        private TemplateDefinitionRegistry $definitions,
        private TenantBoundary $boundary,
        private TenantContext $context,
        private CanonicalJson $json,
    ) {}

    public function execute(ImportPlatformTemplateData $data, TemplateActorData $actor): TemplateVersion
    {
        $tenant = $this->context->requireTenant();
        $grant = TemplateTenantGrant::query()->with(['version.template'])->findOrFail($data->grantId);
        $this->access->assertAllowed($grant->template_version_id, TemplateVersion::CONTENT_GROUP, $data->grantId, $tenant);
        $this->assertGrant($grant, $data);
        $definition = $this->definitions->get($data->targetKey);
        if ($grant->version->template->key !== $data->targetKey
            || $grant->version->template->renderer !== $definition->renderer
            || $this->json->digest($grant->version->template->schema) !== $this->json->digest($definition->schema)) {
            throw new TenantBoundaryViolation('Template catalog source does not match its immutable code definition.');
        }
        $source = $this->exportContent->execute(TemplateVersion::CONTENT_OWNER_TYPE, $grant->template_version_id, TemplateVersion::CONTENT_GROUP, $grant->id);
        $staged = [];
        $snapshots = [];
        $sourceIds = $source->mediaIds;
        sort($sourceIds);
        try {
            foreach ($sourceIds as $sourceId) {
                $grantId = $data->mediaGrantIds[$sourceId]
                    ?? throw new TenantBoundaryViolation("Template copy requires a Media grant for [{$sourceId}].");
                $snapshot = $this->media->inspect($grantId);
                if ($snapshot->sourceId !== $sourceId) {
                    throw new TenantBoundaryViolation('Template Media grant source does not match its Content reference.');
                }
                $snapshots[$sourceId] = $snapshot;
                $staged[$sourceId] = $this->media->stage($snapshot);
            }

            return DB::connection(TemplatesConfiguration::connection())->transaction(function () use ($actor, $data, $definition, $grant, $source, $sourceIds, $snapshots, $staged, $tenant): TemplateVersion {
                $lockedGrant = TemplateTenantGrant::query()->with(['version.template'])->lockForUpdate()->findOrFail($grant->id);
                $this->assertGrant($lockedGrant, $data, $source->sourceHash);
                $existing = $this->boundary->query(TemplateVersion::query(), 'templates.versions')
                    ->where('catalog_import_key', $data->idempotencyKey)->lockForUpdate()->first();
                if ($existing instanceof TemplateVersion) {
                    if ($existing->catalog_source_version_id !== $source->ownerId || $existing->catalog_source_hash !== $source->sourceHash) {
                        throw new TenantBoundaryViolation('Template catalog idempotency key belongs to another source.');
                    }

                    return $existing;
                }
                if ($this->boundary->query(Template::query(), 'templates.templates')->where('key', $data->targetKey)->exists()) {
                    throw new TenantBoundaryViolation('Template target key already exists for this tenant.');
                }
                $template = Template::query()->create([
                    ...$this->boundary->attributes('templates.templates'),
                    'key' => $data->targetKey,
                    'renderer' => $definition->renderer,
                    'status' => 'active',
                    'schema' => $definition->schema,
                    'metadata' => ['catalog_source_key' => $lockedGrant->version->template->key],
                ]);
                $version = TemplateVersion::query()->create([
                    'tenant_id' => $tenant->value,
                    'template_id' => $template->id,
                    'version' => 1,
                    'status' => TemplateVersionStatus::Draft,
                    'metadata' => [],
                    'catalog_grant_id' => $lockedGrant->id,
                    'catalog_source_version_id' => $source->ownerId,
                    'catalog_source_revision' => $source->sourceRevision,
                    'catalog_source_hash' => $source->sourceHash,
                    'catalog_import_key' => $data->idempotencyKey,
                ]);
                $mediaMap = [];
                foreach ($sourceIds as $sourceId) {
                    $copy = $this->media->persist(
                        $snapshots[$sourceId],
                        $staged[$sourceId],
                        $this->mediaImportKey($data->idempotencyKey, $sourceId),
                    );
                    $mediaMap[$sourceId] = (string) $copy->getKey();
                }
                $content = $this->importContent->execute($version, $source, $mediaMap, $actor->contentActor());
                $version->forceFill([
                    'content_snapshot' => $content,
                    'content_hash' => $content->version,
                    'status' => TemplateVersionStatus::Published,
                    'published_by_type' => $actor->type,
                    'published_by' => $actor->id,
                    'published_at' => now(),
                ])->save();

                return $version->refresh();
            });
        } catch (Throwable $exception) {
            foreach ($staged as $file) {
                $this->media->discard($file);
            }
            throw $exception;
        }
    }

    private function assertGrant(
        TemplateTenantGrant $grant,
        ImportPlatformTemplateData $data,
        ?string $sourceHash = null,
    ): void
    {
        if ($grant->revision !== $data->expectedGrantRevision || $grant->source_revision !== $data->expectedSourceRevision
            || $grant->version->revision !== $data->expectedSourceRevision || $grant->revoked_at !== null
            || ($sourceHash !== null && ! hash_equals((string) $grant->version->content_hash, $sourceHash))) {
            throw new TenantBoundaryViolation('Template catalog grant or source revision changed.');
        }
    }

    private function mediaImportKey(string $idempotencyKey, string $sourceId): string
    {
        $hex = substr(hash('sha256', $idempotencyKey."\0".$sourceId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
