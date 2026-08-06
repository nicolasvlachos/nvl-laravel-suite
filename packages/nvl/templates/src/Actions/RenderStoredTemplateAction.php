<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Str;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\RenderTemplateData;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Models\Template as StoredTemplate;
use Nvl\Templates\Services\StoredTemplateRenderResolver;

/**
 * Adapts the database implementation into the core Template rendering action.
 *
 * This deliberate action composition keeps stored and directly constructed
 * templates on one validation and renderer pipeline.
 */
final readonly class RenderStoredTemplateAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private StoredTemplateRenderResolver $resolver,
        private RenderTemplateAction $renderTemplate,
    ) {}

    /**
     * Resolve and render one published stored template.
     */
    public function execute(
        StoredTemplate|string $template,
        RenderTemplateData $data,
        TemplateActorData $actor,
    ): RenderedTemplateData {
        $model = $template instanceof StoredTemplate
            ? $template
            : StoredTemplate::query()
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
                'queued' => false,
            ],
        );
        $resolved = $this->resolver->resolve($model, $data, $actor);

        return $this->renderTemplate->execute($resolved->renderable);
    }
}
