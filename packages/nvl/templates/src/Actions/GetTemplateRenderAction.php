<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Models\TemplateRender;

/**
 * Returns one authorized durable render with its private Media reference loaded.
 */
final readonly class GetTemplateRenderAction
{
    public function __construct(private TemplateAuthorization $authorization) {}

    /**
     * Resolve and authorize one durable render record.
     */
    public function execute(
        TemplateRender|string $render,
        TemplateActorData $actor,
    ): TemplateRender {
        $model = $render instanceof TemplateRender
            ? $render
            : TemplateRender::query()->findOrFail($render);
        $this->authorization->authorize(
            TemplateAbility::View,
            $actor,
            [
                'resource' => 'template_render',
                'render_id' => $model->id,
                'template_id' => $model->template_id,
                'requested_by_type' => $model->requested_by_type,
                'requested_by' => $model->requested_by,
            ],
        );
        $this->assertOwnership($model, $actor);

        return $model->loadMissing('media');
    }

    private function assertOwnership(
        TemplateRender $render,
        TemplateActorData $actor,
    ): void {
        if (! $actor->system
            && ($actor->type === null
                || $actor->id === null
                || $render->requested_by_type !== $actor->type
                || $render->requested_by !== $actor->id)) {
            throw new AuthorizationException(
                'The actor may only view its own template renders.',
            );
        }
    }
}
