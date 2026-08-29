<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Relations\ExactTextValueComparison;
use Nvl\Content\Services\ContentIdentityGuard;

/**
 * Finds one unambiguous editable block by its exact portable key.
 */
final readonly class FindContentBlockByKeyAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentIdentityGuard $identities,
    ) {}

    /**
     * Return one authorized editable block with an exact unambiguous key.
     */
    public function execute(string $key, ContentActorData $actor): ContentBlockData
    {
        $this->identities->blockKey($key);
        $query = ContentBlock::query()
            ->with(['definition', 'translations']);
        $driver = $query->getModel()->getConnection()->getDriverName();
        $wrappedKey = $query->getQuery()->getGrammar()->wrap(
            $query->qualifyColumn('key'),
        );
        $blocks = $query
            ->whereRaw(
                new ExactTextValueComparison($wrappedKey, $driver),
                [$key, $key],
            )
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($blocks->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(ContentBlock::class, [$key]);
        }

        foreach ($blocks as $block) {
            $this->authorization->authorize(ContentAbility::View, $actor, $block);
        }

        if ($blocks->count() !== 1) {
            throw new InvalidArgumentException(
                "Content block key [{$key}] is ambiguous across active scopes.",
            );
        }

        return ContentBlockData::fromModel($blocks->firstOrFail());
    }
}
