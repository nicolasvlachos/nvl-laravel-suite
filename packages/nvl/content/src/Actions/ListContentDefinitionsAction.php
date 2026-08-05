<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Collection;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Services\ContentDefinitionRegistry;

/**
 * Lists the schemas available to an authorized headless content editor.
 */
final readonly class ListContentDefinitionsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentDefinitionRegistry $definitions,
    ) {}

    /**
     * @return Collection<int, ContentDefinitionData>
     */
    public function execute(ContentActorData $actor): Collection
    {
        $this->authorization->authorize(ContentAbility::ListDefinitions, $actor);

        return collect($this->definitions->all())
            ->filter(static fn (ContentDefinitionData $definition): bool => $definition->isActive)
            ->sortBy([
                ['sortOrder', 'asc'],
                ['key', 'asc'],
            ])
            ->values();
    }
}
