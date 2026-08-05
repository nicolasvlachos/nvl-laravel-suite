<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use LogicException;
use Nvl\Seo\Contracts\SeoImportSource;
use Nvl\Seo\Data\Import\SeoImportResultData;
use Nvl\Seo\Services\SeoOwnerRegistry;

/**
 * Imports one bounded, rerunnable page through canonical profile Actions.
 */
final readonly class ImportSeoProfilesAction
{
    public function __construct(
        private SeoOwnerRegistry $owners,
        private SyncSeoProfileAction $sync,
    ) {}

    /**
     * Import one bounded page and return its continuation contract.
     */
    public function execute(
        SeoImportSource $source,
        ?string $cursor = null,
        int $limit = 100,
    ): SeoImportResultData {
        $limit = max(1, min(500, $limit));
        $page = $source->page($cursor, $limit);

        if (count($page->items) > $limit) {
            throw new LogicException(
                "The SEO import source returned more than its requested {$limit}-record page.",
            );
        }

        $processed = 0;

        foreach ($page->items as $record) {
            $owner = $this->owners->resolve($record->ownerAlias, $record->ownerId);
            $this->sync->execute($owner, $record->profile, $record->scope);
            $processed++;
        }

        return new SeoImportResultData(
            processed: $processed,
            nextCursor: $page->nextCursor,
        );
    }
}
