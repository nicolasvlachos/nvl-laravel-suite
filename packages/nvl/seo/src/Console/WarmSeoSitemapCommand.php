<?php

declare(strict_types=1);

namespace Nvl\Seo\Console;

use Illuminate\Console\Command;
use Nvl\Seo\Exceptions\InvalidSeoMutationException;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoScope;

/**
 * Builds and publishes sitemap artifacts for one scope.
 */
final class WarmSeoSitemapCommand extends Command
{
    protected $signature = 'nvl:seo:sitemap:warm
        {--scope= : Site scope to build}';

    protected $description = 'Build and publish SEO sitemap artifacts for one scope';

    /**
     * Build the requested sitemap and report its published shape.
     */
    public function handle(SitemapGenerator $sitemaps): int
    {
        if (SeoConfiguration::nonNegativeInteger('seo.sitemap.cache_seconds', 3600) === 0) {
            $this->components->error(
                'Sitemap warming requires a positive seo.sitemap.cache_seconds value.',
            );

            return self::INVALID;
        }

        $scope = $this->option('scope');

        try {
            $scope = SeoScope::normalize(is_string($scope) && $scope !== '' ? $scope : null);
        } catch (InvalidSeoMutationException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $xml = $sitemaps->generate($scope);
        $chunks = $sitemaps->chunkCount($scope);
        $this->components->info(
            "Published {$chunks} sitemap chunk(s) for scope [{$scope}] "
            .'with '.strlen($xml).' bytes in the primary document.',
        );

        return self::SUCCESS;
    }
}
