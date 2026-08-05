<?php

declare(strict_types=1);

namespace Nvl\Seo\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Seo\Events\SeoProfileChanged;
use Nvl\Seo\Exceptions\StaleSeoProfileException;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SitemapCache;

/**
 * Archives or restores a profile without destroying historical metadata.
 */
final readonly class ArchiveSeoProfileAction
{
    public function __construct(private SitemapCache $sitemapCache) {}

    public function execute(
        SeoProfile|string $profile,
        bool $archived,
        int $expectedRevision,
    ): SeoProfile {
        $profileId = $profile instanceof SeoProfile ? $profile->id : $profile;

        return DB::transaction(function () use ($profileId, $archived, $expectedRevision): SeoProfile {
            $profile = SeoProfile::query()
                ->lockForUpdate()
                ->findOrFail($profileId);

            if ($profile->revision !== $expectedRevision) {
                throw StaleSeoProfileException::forProfile($profile->id);
            }

            $profile->status = $archived ? 'archived' : 'active';
            $profile->archived_at = $archived ? now() : null;
            $profile->save();
            $profile->refresh()->load('translations');
            DB::afterCommit(function () use ($profile): void {
                $this->sitemapCache->forget($profile->scope);
            });
            SeoProfileChanged::dispatch(
                $profile->id,
                $profile->scope,
                $archived ? 'archived' : 'restored',
            );

            return $profile;
        });
    }
}
