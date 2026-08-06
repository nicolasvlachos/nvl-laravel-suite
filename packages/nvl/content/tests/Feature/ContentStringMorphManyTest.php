<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Facades\Content;
use Nvl\Content\Tests\Fixtures\TestIntegerContentOwner;
use Nvl\Content\Traits\HasContent;

beforeEach(function (): void {
    app(SyncContentDefinitionsAction::class)->execute(ContentActorData::system());
});

it('supports integer owner keys across relation reads writes and existence queries', function (): void {
    $owner = TestIntegerContentOwner::query()->create(['name' => 'Primary owner']);
    $emptyOwner = TestIntegerContentOwner::query()->create(['name' => 'Empty owner']);
    $block = Content::createBlock(
        new CreateContentBlockData(
            definition: 'hero',
            key: 'integer-owner-relation',
            scope: 'site',
            scopeKey: 'main-site',
            translations: ['en' => ['title' => 'Integer owner relation']],
        ),
        ContentActorData::system(),
    );

    $placement = $owner->contentPlacements()
        ->withAttributes(['overrides' => ['layout' => 'wide']])
        ->create([
            'content_block_id' => $block->id,
            'group' => 'default',
            'key' => 'hero',
        ]);

    expect($placement->owner_id)->toBe((string) $owner->getKey())
        ->and($placement->owner_type)->toBe($owner->getMorphClass())
        ->and($placement->overrides)->toBe(['layout' => 'wide'])
        ->and($owner->fresh()?->contentPlacements)->toHaveCount(1)
        ->and(TestIntegerContentOwner::query()
            ->with('contentPlacements')
            ->findOrFail($owner->getKey())
            ->contentPlacements)->toHaveCount(1)
        ->and(TestIntegerContentOwner::query()
            ->with('contentPlacements')
            ->findOrFail($emptyOwner->getKey())
            ->contentPlacements)->toBeEmpty()
        ->and(TestIntegerContentOwner::query()
            ->whereHas('contentPlacements')
            ->pluck('id')
            ->all())->toBe([$owner->getKey()]);
});

it('handles absent keys and rejects compound owner identifiers', function (): void {
    $unsaved = new TestIntegerContentOwner;

    expect($unsaved->contentPlacements()->toSql())->toBeString();

    $compoundOwner = new class extends Model implements ContentOwner
    {
        use HasContent;

        public function getAttribute($key): mixed
        {
            return $key === $this->getKeyName()
                ? ['compound']
                : parent::getAttribute($key);
        }
    };
    $relation = Relation::noConstraints(
        fn () => $compoundOwner->contentPlacements(),
    );

    expect(fn () => $relation->make())
        ->toThrow(
            InvalidArgumentException::class,
            'Content owner identifiers must be integers or strings.',
        );
});
