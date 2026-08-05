<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Services\TemplateRenderFilterSchema;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Lists authorized durable render history through a fixed query allowlist.
 */
final readonly class ListTemplateRendersAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private EloquentFilterApplier $filters,
        private TemplateRenderFilterSchema $filterSchema,
    ) {}

    /**
     * Return one deterministic page of durable render history.
     *
     * @return LengthAwarePaginator<int, TemplateRender>
     */
    public function execute(
        FilterSet $filterSet,
        TemplateActorData $actor,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $this->authorization->authorize(
            TemplateAbility::View,
            $actor,
            ['resource' => 'template_render_history'],
        );
        $query = TemplateRender::query()->with('media');

        if (! $actor->system) {
            if ($actor->type === null || $actor->id === null) {
                throw new AuthorizationException(
                    'Template render history requires a stable actor identifier.',
                );
            }

            $query->where('requested_by_type', $actor->type)
                ->where('requested_by', $actor->id);
        }

        $this->filters->apply($query, $filterSet, $this->filterSchema->make());
        $perPage ??= TemplatesConfiguration::limit('per_page', 25);

        return $query->paginate(max(
            1,
            min(TemplatesConfiguration::limit('maximum_per_page', 100), $perPage),
        ));
    }
}
