<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Services\EloquentFilterApplier;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Services\TemplateFilterSchema;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Lists templates through Action authorization and a fixed query allowlist.
 */
final readonly class ListTemplatesAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private EloquentFilterApplier $filters,
        private TemplateFilterSchema $filterSchema,
    ) {}

    /**
     * Return one configured and deterministically sorted template page.
     *
     * @return LengthAwarePaginator<int, Template>
     */
    public function execute(
        FilterSet $filterSet,
        TemplateActorData $actor,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $this->authorization->authorize(TemplateAbility::List, $actor);
        $query = Template::query()->with('translations');
        $this->filters->apply($query, $filterSet, $this->filterSchema->make());
        $perPage ??= TemplatesConfiguration::limit('per_page', 25);

        return $query->paginate(max(
            1,
            min(TemplatesConfiguration::limit('maximum_per_page', 100), $perPage),
        ));
    }
}
