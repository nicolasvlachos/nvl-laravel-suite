<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Seo\Tests\Fixtures\TestIntegerSeoOwner;
use Nvl\Seo\Traits\HasSeo;

it('supports integer owner keys across relation reads writes and existence queries', function (): void {
    $owner = TestIntegerSeoOwner::query()->create(['name' => 'Primary owner']);
    $emptyOwner = TestIntegerSeoOwner::query()->create(['name' => 'Empty owner']);

    $profile = $owner->seoProfiles()
        ->withAttributes(['metadata' => ['source' => 'relation']])
        ->create(['scope' => 'default']);

    expect($profile->seoable_id)->toBe((string) $owner->getKey())
        ->and($profile->seoable_type)->toBe($owner->getMorphClass())
        ->and($profile->metadata)->toBe(['source' => 'relation'])
        ->and($owner->fresh()?->seoProfiles)->toHaveCount(1)
        ->and(TestIntegerSeoOwner::query()
            ->with('seoProfiles')
            ->findOrFail($owner->getKey())
            ->seoProfiles)->toHaveCount(1)
        ->and(TestIntegerSeoOwner::query()
            ->with('seoProfiles')
            ->findOrFail($emptyOwner->getKey())
            ->seoProfiles)->toBeEmpty()
        ->and(TestIntegerSeoOwner::query()
            ->whereHas('seoProfiles')
            ->pluck('id')
            ->all())->toBe([$owner->getKey()]);
});

it('handles absent keys and rejects compound owner identifiers', function (): void {
    $unsaved = new TestIntegerSeoOwner;

    expect($unsaved->seoProfiles()->toSql())->toBeString();

    $compoundOwner = new class extends Model
    {
        use HasSeo;

        public function getAttribute($key): mixed
        {
            return $key === $this->getKeyName()
                ? ['compound']
                : parent::getAttribute($key);
        }
    };
    $relation = Relation::noConstraints(
        fn () => $compoundOwner->seoProfiles(),
    );

    expect(fn () => $relation->make())
        ->toThrow(
            InvalidArgumentException::class,
            'SEO owner identifiers must be integers or strings.',
        );
});
