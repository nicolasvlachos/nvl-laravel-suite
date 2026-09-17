<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Jobs\TenantProbeJob;
use App\Models\TenantArticle;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use RuntimeException;
use Throwable;

/** Implements the shared Media, adoption, queue, and ownership proof for both profiles. */
final readonly class MediaProof
{
    /** Create the fixture proof around public package boundaries. */
    public function __construct(
        private TenantAdoptionCoordinator $adoption,
        private TenantRunner $tenants,
        private TenantBoundary $boundary,
        private UploadMediaAction $upload,
        private AttachMediaAction $attach,
        private UpdateMediaMetadataAction $update,
        private MediaQueryService $media,
        private TenantContext $context,
        private Filesystem $files,
        private FilesystemFactory $storage,
    ) {}

    /**
     * Adopt one legacy root through an interrupted/resumed plan.
     *
     * @param list<string> $packages
     * @return array{legacy_article_id: string, adoption_run_id: string, adoption_interrupted: bool, adoption_resumed: bool}
     */
    public function adopt(array $packages, TenantId $tenantA): array
    {
        $legacy = TenantArticle::query()->create([
            'slug' => 'legacy',
            'title' => 'Known legacy article',
        ]);
        $operation = $this->operation('adoption');
        $plan = $this->adoption->prepare($packages, [
            new TenantAssignment('consumer.articles', $legacy->id, $tenantA),
        ], $operation);
        $interrupted = ! $this->adoption->backfill($plan, 1, $operation);
        $resumed = $this->adoption->resume($plan->id);
        $complete = false;
        for ($batch = 0; $batch < 20 && ! $complete; $batch++) {
            $complete = $this->adoption->backfill($resumed, 100, $operation);
        }
        if (! $interrupted || ! $complete || ! $this->adoption->verify($resumed)->passed()) {
            throw new RuntimeException('The tenancy consumer adoption rehearsal did not verify.');
        }
        $this->adoption->activate($resumed, $operation);

        return [
            'legacy_article_id' => $legacy->id,
            'adoption_run_id' => $resumed->id,
            'adoption_interrupted' => $interrupted,
            'adoption_resumed' => true,
        ];
    }

    /**
     * Create one tenant article and equal-byte asset through public Media Actions.
     *
     * @return array{article_id: string, media_id: string, path: string, digest: string, binary_hash: string, association_count: int}
     */
    public function seedTenant(TenantId $tenant, string $label): array
    {
        return $this->tenants->run($tenant, function () use ($label): array {
            $article = new TenantArticle([
                'slug' => 'same-slug',
                'title' => 'Tenant '.$label,
            ]);
            $article->forceFill($this->boundary->attributes('consumer.articles'));
            $article->saveOrFail();

            $path = tempnam(sys_get_temp_dir(), 'nvl-tenancy-consumer-');
            if (! is_string($path)) {
                throw new RuntimeException('Unable to allocate the Media upload fixture.');
            }
            $this->files->put($path, 'identical tenant media bytes');
            try {
                $media = $this->upload->execute(
                    file: new UploadedFile($path, 'same.txt', 'text/plain', null, true),
                    disk: 'tenant-media',
                    model: $article,
                    slot: new MediaSlot('document'),
                    fileName: 'same.txt',
                    isPublic: false,
                    skipAutoVariations: true,
                );
            } finally {
                $this->files->delete($path);
            }
            $this->attach->execute(
                media: $media,
                model: $article,
                collection: 'document',
                metadata: ['slot' => 'document'],
                dispatchVariations: false,
            );
            $media = $this->media->show($media->id, false);

            return [
                'article_id' => $article->id,
                'media_id' => $media->id,
                'path' => $media->buildPath(),
                'digest' => (string) $media->digest,
                'binary_hash' => hash('sha256', $this->storage->disk((string) $media->disk)->get($media->buildPath())),
                'association_count' => $media->associations->count(),
            ];
        });
    }

    /** Dispatch A/B, terminal failure, and deliberately envelope-less database jobs. */
    public function dispatchProbes(TenantId $tenantA, TenantId $tenantB): void
    {
        $this->dispatchProbe($tenantA, 'tenant-a', false);
        $this->dispatchProbe($tenantB, 'tenant-b', false);
        $this->dispatchProbe($tenantA, 'tenant-a-failure', true);
        $this->dispatchProbe($tenantA, 'missing-envelope', false);

        $job = DB::table('jobs')->orderByDesc('id')->first();
        if (! is_object($job) || ! is_string($job->payload ?? null)) {
            throw new RuntimeException('The missing-envelope probe was not persisted.');
        }
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array(data_get($payload, 'data.nvl_tenancy'))) {
            throw new RuntimeException('The queued probe has no tenant envelope to remove.');
        }
        data_forget($payload, 'data.nvl_tenancy');
        DB::table('jobs')->where('id', $job->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Verify tenant Media identity, canonical ownership, and real worker observations.
     *
     * @param array{article_id: string, media_id: string, path: string, digest: string, binary_hash: string, association_count: int} $assetA
     * @param array{article_id: string, media_id: string, path: string, digest: string, binary_hash: string, association_count: int} $assetB
     * @return array<string, bool>
     */
    public function verify(TenantId $tenantA, TenantId $tenantB, array $assetA, array $assetB): array
    {
        $foreignIdDenied = $this->denied(fn () => $this->tenants->run(
            $tenantB,
            fn (): Media => $this->media->show($assetA['media_id'], false),
        ));
        $loadedMedia = $this->tenants->run(
            $tenantA,
            fn (): Media => $this->media->show($assetA['media_id'], false),
        );
        $foreignModelDenied = $this->denied(fn () => $this->tenants->run(
            $tenantB,
            fn (): Media => $this->update->execute(
                $loadedMedia,
                new UpdateMediaPayload(metadata: ['forged' => true]),
            ),
        ));
        $loadedOwner = $this->tenants->run($tenantA, function () use ($assetA): TenantArticle {
            $article = $this->boundary->query(TenantArticle::query(), 'consumer.articles')
                ->findOrFail($assetA['article_id']);
            $article->load('media');

            return $article;
        });
        $loadedRelationDenied = $this->denied(fn () => $this->tenants->run(
            $tenantB,
            fn () => $loadedOwner->getMedia('document'),
        ));
        $canonicalA = $this->tenants->run(
            $tenantA,
            fn (): Media => $this->media->show($assetA['media_id'], false),
        );
        $canonicalB = $this->tenants->run(
            $tenantB,
            fn (): Media => $this->media->show($assetB['media_id'], false),
        );
        $binaryHashA = hash('sha256', $this->storage->disk((string) $canonicalA->disk)->get($canonicalA->buildPath()));
        $binaryHashB = hash('sha256', $this->storage->disk((string) $canonicalB->disk)->get($canonicalB->buildPath()));
        $observations = DB::table('tenant_probe_observations')
            ->orderBy('id')
            ->get(['probe', 'phase', 'tenant_id'])
            ->map(static fn (object $row): string => $row->probe.':'.$row->phase.':'.$row->tenant_id)
            ->all();

        return [
            'equal_bytes_have_distinct_ids' => $canonicalA->id !== $canonicalB->id
                && $canonicalA->digest === $canonicalB->digest
                && $binaryHashA === $binaryHashB,
            'equal_bytes_have_distinct_paths' => $canonicalA->buildPath() !== $canonicalB->buildPath()
                && str_contains($canonicalA->buildPath(), 'tenants/'.$tenantA->value.'/')
                && str_contains($canonicalB->buildPath(), 'tenants/'.$tenantB->value.'/'),
            'foreign_id_denied' => $foreignIdDenied,
            'foreign_model_denied' => $foreignModelDenied,
            'loaded_relation_denied' => $loadedRelationDenied,
            'tenant_a_unchanged' => $canonicalA->buildPath() === $assetA['path']
                && $canonicalA->digest === $assetA['digest']
                && $binaryHashA === $assetA['binary_hash']
                && $canonicalA->associations->count() === $assetA['association_count']
                && $canonicalA->metadata === null,
            'worker_handle_a' => in_array('tenant-a:handle:'.$tenantA->value, $observations, true),
            'worker_handle_b' => in_array('tenant-b:handle:'.$tenantB->value, $observations, true),
            'worker_failure_handle_a' => in_array('tenant-a-failure:handle:'.$tenantA->value, $observations, true),
            'worker_failed_a' => in_array('tenant-a-failure:failed:'.$tenantA->value, $observations, true),
            'missing_envelope_no_write' => count(array_filter(
                $observations,
                static fn (string $observation): bool => str_starts_with($observation, 'missing-envelope:'),
            )) === 0,
            'global_context_unresolved' => $this->context->snapshot()->mode === TenantContextMode::Unresolved,
        ];
    }

    /** Return the fixture's exact platform operation. */
    public function operation(string $purpose): PlatformOperation
    {
        return new PlatformOperation(
            'tenancy-consumer.'.$purpose,
            'system',
            'tenancy-production-consumer',
        );
    }

    /** Dispatch one scalar probe from its producer tenant. */
    private function dispatchProbe(TenantId $tenant, string $probe, bool $shouldFail): void
    {
        $this->tenants->run($tenant, function () use ($probe, $shouldFail): void {
            Bus::dispatch((new TenantProbeJob(
                $probe,
                $shouldFail,
                TenantJobEnvelope::capture($this->context),
            ))->onConnection('database')->onQueue('tenancy-proof'));
        });
    }

    /** Return true only when the operation fails closed at a tenant boundary. */
    private function denied(callable $operation): bool
    {
        try {
            $operation();
        } catch (TenantBoundaryViolation|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return true;
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
