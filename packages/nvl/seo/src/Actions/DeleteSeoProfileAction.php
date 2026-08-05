<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Seo\Events\SeoProfileChanged;
use Nvl\Seo\Exceptions\StaleSeoProfileException;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SitemapCache;

/**
 * Deletes one SEO profile and its translations atomically.
 */
final readonly class DeleteSeoProfileAction
{
    public function __construct(
        private SitemapCache $sitemapCache,
    ) {}

    public function execute(SeoProfile|string $profile, ?int $expectedRevision = null): bool
    {
        $profileId = $profile instanceof SeoProfile ? $profile->id : $profile;

        return DB::transaction(function () use ($profileId, $expectedRevision): bool {
            $profile = SeoProfile::query()
                ->lockForUpdate()
                ->findOrFail($profileId);

            if ($expectedRevision !== null && $profile->revision !== $expectedRevision) {
                throw StaleSeoProfileException::forProfile($profile->id);
            }

            $id = $profile->id;
            $scope = $profile->scope;
            $profile->translations()->delete();
            $deleted = (bool) $profile->delete();

            if ($deleted) {
                DB::afterCommit(function () use ($scope): void {
                    $this->sitemapCache->forget($scope);
                });
                SeoProfileChanged::dispatch($id, $scope, 'deleted');
            }

            return $deleted;
        });
    }
}
