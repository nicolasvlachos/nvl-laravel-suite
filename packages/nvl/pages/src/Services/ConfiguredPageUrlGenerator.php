<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\Pages\Contracts\PageUrlGenerator;
use Nvl\Pages\Models\Page;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

/**
 * Builds absolute URLs from a configured site base and optional locale prefix.
 */
final class ConfiguredPageUrlGenerator implements PageUrlGenerator
{
    /** Create the URL generator from request/job-scoped verified site identity. */
    public function __construct(private Repository $configuration, private Container $container) {}

    /**
     * Build one absolute configured page URL.
     */
    public function url(Page $page, ?string $locale = null): string
    {
        $tenantSite = $this->configuration->get('tenancy.enabled') === true
            ? $this->container->make(TenantSiteContext::class)
            : null;
        if ($tenantSite !== null && $page->site !== $tenantSite->site) {
            throw new TenantBoundaryViolation('The Page site differs from the verified public site.');
        }
        $base = $tenantSite instanceof TenantSiteContext
            ? $tenantSite->canonicalOrigin
            : config('pages.urls.base_url', config('app.url', 'http://localhost'));

        if (! is_string($base)
            || filter_var($base, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('pages.urls.base_url must be an absolute HTTP URL.');
        }

        $segments = [];
        $defaultLocale = config('pages.urls.default_locale', config('app.locale', 'en'));

        if ((bool) config('pages.urls.locale_prefix', false)
            && $locale !== null
            && $locale !== $defaultLocale) {
            $segments[] = rawurlencode($locale);
        }

        $segments[] = $page->path;

        return rtrim($base, '/').'/'.implode('/', $segments);
    }
}
