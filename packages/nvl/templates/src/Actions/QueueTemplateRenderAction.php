<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\RenderTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Exceptions\TemplatesException;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Rendering\ResolvedStoredTemplateRender;
use Nvl\Templates\Services\CanonicalJson;
use Nvl\Templates\Services\StoredTemplateRenderResolver;
use Nvl\Templates\Services\TemplateRenderDispatcher;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Persists and dispatches one idempotent asynchronous render request.
 */
final readonly class QueueTemplateRenderAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private StoredTemplateRenderResolver $resolver,
        private CanonicalJson $canonicalJson,
        private TemplateRenderDispatcher $dispatcher,
        private TenantBoundary $boundary,
        private TenantContext $context,
    ) {}

    public function execute(
        Template|string $template,
        RenderTemplateData $data,
        TemplateActorData $actor,
    ): TemplateRender {
        $model = $template instanceof Template
            ? $this->boundary->query(Template::query(), 'templates.templates')->findOrFail($template->id)
            : $this->boundary->query(Template::query(), 'templates.templates')
                ->when(
                    Str::isUuid($template),
                    static fn ($query) => $query
                        ->where('id', $template)
                        ->orWhere('key', $template),
                    static fn ($query) => $query->where('key', $template),
                )
                ->firstOrFail();
        $this->authorization->authorize(
            TemplateAbility::Render,
            $actor,
            [
                'template_id' => $model->id,
                'owner_type' => $data->ownerType,
                'owner_id' => $data->ownerId,
                'profile' => $data->profile,
                'version_id' => $data->versionId,
                'queued' => true,
            ],
        );
        $resolved = $this->resolver->resolve($model, $data, $actor);
        $requestDigest = $this->canonicalJson->digest([
            'template_id' => $model->id,
            'locale' => $resolved->locale,
            'payload' => $resolved->payload,
            'owner_type' => $data->ownerType,
            'owner_id' => $data->ownerId,
            'profile' => $data->profile,
            'version_id' => $data->versionId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
        ]);
        $envelope = TenantJobEnvelope::capture($this->context);

        try {
            return DB::connection(TemplatesConfiguration::connection())
                ->transaction(function () use (
                    $actor,
                    $data,
                    $model,
                    $resolved,
                    $requestDigest,
                    $envelope,
                ): TemplateRender {
                    if ($data->idempotencyKey !== null) {
                        $existing = $this->boundary->query(TemplateRender::query(), 'templates.renders')
                            ->where('idempotency_key', $data->idempotencyKey)
                            ->lockForUpdate()
                            ->first();

                        if ($existing !== null) {
                            $this->assertEquivalent(
                                $existing,
                                $model,
                                $resolved,
                                $actor,
                                $requestDigest,
                            );

                            return $existing;
                        }
                    }

                    $render = TemplateRender::query()->create([
                        ...(config('tenancy.enabled') === true ? ['tenant_id' => $model->tenant_id] : []),
                        'template_id' => $model->id,
                        'template_version_id' => $resolved->version->id,
                        'template_assignment_id' => $resolved->assignment?->id,
                        'locale' => $resolved->locale,
                        'profile' => $data->profile,
                        'settings' => $resolved->renderable->settings,
                        'status' => TemplateRenderStatus::Pending,
                        'idempotency_key' => $data->idempotencyKey,
                        'payload_digest' => $requestDigest,
                        'payload' => $resolved->payload,
                        'requested_by_type' => $actor->type,
                        'requested_by' => $actor->id,
                    ]);

                    DB::connection(TemplatesConfiguration::connection())
                        ->afterCommit(function () use ($envelope, $render): void {
                            $this->dispatcher->dispatch($render->id, $envelope);
                        });

                    return $render;
                });
        } catch (UniqueConstraintViolationException $exception) {
            if ($data->idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->boundary->query(TemplateRender::query(), 'templates.renders')
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $this->assertEquivalent(
                $existing,
                $model,
                $resolved,
                $actor,
                $requestDigest,
            );

            return $existing;
        }
    }

    private function assertEquivalent(
        TemplateRender $render,
        Template $template,
        ResolvedStoredTemplateRender $resolved,
        TemplateActorData $actor,
        string $requestDigest,
    ): void {
        if (! hash_equals($render->payload_digest, $requestDigest)
            || $render->template_id !== $template->id
            || $render->locale !== $resolved->locale
            || $render->requested_by_type !== $actor->type
            || $render->requested_by !== $actor->id) {
            throw new TemplatesException(
                'The template render idempotency key was already used for another request.',
            );
        }
    }
}
