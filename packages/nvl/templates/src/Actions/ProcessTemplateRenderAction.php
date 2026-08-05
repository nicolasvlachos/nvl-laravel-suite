<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Events\TemplateRendered;
use Nvl\Templates\Exceptions\TemplateResolutionException;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Services\StoredTemplateRenderResolver;
use Nvl\Templates\Services\TemplateOutputGuard;
use Nvl\Templates\Support\TemplatesConfiguration;
use Throwable;

/**
 * Orchestrates the durable lease lifecycle through canonical template rendering.
 *
 * Delegation to RenderTemplateAction is deliberate action composition so queued
 * and direct rendering share validation, renderer selection, and output rules.
 */
final readonly class ProcessTemplateRenderAction
{
    public function __construct(
        private StoredTemplateRenderResolver $resolver,
        private RenderTemplateAction $renderTemplate,
        private TemplateOutputGuard $outputGuard,
    ) {}

    /**
     * Process one render only while the supplied lease token remains its owner.
     */
    public function execute(
        TemplateRender|string $render,
        ?string $processingToken = null,
        ?int $dispatchGeneration = null,
    ): TemplateRender {
        $renderId = $render instanceof TemplateRender ? $render->id : $render;
        $processingToken ??= (string) Str::uuid();
        $model = $this->claim(
            $renderId,
            $processingToken,
            $dispatchGeneration,
        );

        if ($model->status !== TemplateRenderStatus::Processing
            || $model->processing_token !== $processingToken) {
            return $model;
        }

        try {
            $resolved = $this->resolver->resolveDurable($model);
            $result = $this->renderTemplate->execute($resolved->renderable);

            return $this->complete($model->id, $processingToken, $result);
        } catch (Throwable $exception) {
            $this->fail($model->id, $processingToken, $exception);

            throw $exception;
        }
    }

    private function claim(
        string $renderId,
        string $processingToken,
        ?int $dispatchGeneration,
    ): TemplateRender {
        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use (
                $dispatchGeneration,
                $renderId,
                $processingToken,
            ): TemplateRender {
                $render = TemplateRender::query()->lockForUpdate()->findOrFail($renderId);

                if (($dispatchGeneration !== null
                    && $render->dispatch_generation !== $dispatchGeneration)
                    || $render->status === TemplateRenderStatus::Completed
                    || ($render->status === TemplateRenderStatus::Processing
                        && $render->lease_expires_at?->isFuture())) {
                    return $render;
                }

                $leaseSeconds = TemplatesConfiguration::positiveInteger(
                    'templates.rendering.lease_seconds',
                    75,
                );
                $render->fill([
                    'status' => TemplateRenderStatus::Processing,
                    'attempts' => $render->attempts + 1,
                    'processing_token' => $processingToken,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'started_at' => now(),
                    'completed_at' => null,
                    'failed_at' => null,
                    'failure' => null,
                ])->save();

                return $render->refresh();
            });
    }

    private function complete(
        string $renderId,
        string $processingToken,
        RenderedTemplateData $result,
    ): TemplateRender {
        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use (
                $processingToken,
                $renderId,
                $result,
            ): TemplateRender {
                $render = TemplateRender::query()->lockForUpdate()->findOrFail($renderId);
                $this->assertLeaseOwner($render, $processingToken);
                $extension = match (true) {
                    str_starts_with($result->mimeType, 'application/pdf') => 'pdf',
                    str_starts_with($result->mimeType, 'text/html') => 'html',
                    default => 'txt',
                };
                $fileName = sprintf(
                    '%s-%s.%s',
                    TemplatesConfiguration::string(
                        'templates.rendering.output.filename_prefix',
                        'template-render',
                    ),
                    $render->id,
                    $extension,
                );
                $this->outputGuard->validateFilename($fileName);

                if ((bool) config('templates.rendering.output.persist', true)) {
                    $disk = TemplatesConfiguration::string(
                        'templates.rendering.output.disk',
                        'local',
                    );
                    $render->addMediaFromString($result->content)
                        ->usingFileName($fileName)
                        ->toDisk($disk)
                        ->slot('output');
                }

                $render->fill([
                    'status' => TemplateRenderStatus::Completed,
                    'processing_token' => null,
                    'lease_expires_at' => null,
                    'output_name' => $result->suggestedFilename ?? $fileName,
                    'output_mime_type' => $result->mimeType,
                    'completed_at' => now(),
                    'failed_at' => null,
                    'failure' => null,
                    'payload' => (bool) config('templates.rendering.store_payload', true)
                        ? $render->payload
                        : null,
                    'settings' => (bool) config('templates.rendering.store_payload', true)
                        ? $render->settings
                        : null,
                ])->save();
                TemplateRendered::dispatch(
                    $render->id,
                    $render->template_id,
                    $render->template_version_id,
                );

                return $render->refresh();
            });
    }

    private function fail(
        string $renderId,
        string $processingToken,
        Throwable $exception,
    ): void {
        DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use ($exception, $processingToken, $renderId): void {
                $render = TemplateRender::query()->lockForUpdate()->findOrFail($renderId);

                if ($render->status !== TemplateRenderStatus::Processing
                    || $render->processing_token !== $processingToken) {
                    return;
                }

                $render->fill([
                    'status' => TemplateRenderStatus::Failed,
                    'processing_token' => null,
                    'lease_expires_at' => null,
                    'failure' => mb_substr($exception->getMessage(), 0, 4_000),
                    'failed_at' => now(),
                ])->save();
            });
    }

    private function assertLeaseOwner(
        TemplateRender $render,
        string $processingToken,
    ): void {
        if ($render->status !== TemplateRenderStatus::Processing
            || $render->processing_token !== $processingToken) {
            throw new TemplateResolutionException(
                "Template render [{$render->id}] lost its processing lease.",
            );
        }
    }
}
