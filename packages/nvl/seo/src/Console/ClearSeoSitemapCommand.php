<?php

declare(strict_types=1);

namespace Nvl\Seo\Console;

use Illuminate\Console\Command;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Services\SitemapCache;
use Nvl\Seo\Support\SeoScope;

/**
 * Invalidates every published sitemap artifact for one scope.
 */
final class ClearSeoSitemapCommand extends Command
{
    protected $signature = 'nvl:seo:sitemap:clear
        {--scope= : Site scope to invalidate}';

    protected $description = 'Invalidate published SEO sitemap artifacts for one scope';

    /**
     * Advance the sitemap version and delete its previous artifact namespace.
     */
    public function handle(SitemapCache $cache): int
    {
        $scope = $this->option('scope');

        try {
            $scope = SeoScope::normalize(is_string($scope) && $scope !== '' ? $scope : null);
        } catch (InvalidSeoMutationException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        if (! $cache->forget($scope)) {
            $this->components->error(
                "Sitemap artifacts for scope [{$scope}] could not be invalidated.",
            );

            return self::FAILURE;
        }

        $this->components->info("Sitemap artifacts for scope [{$scope}] were invalidated.");

        return self::SUCCESS;
    }
}
