<?php

declare(strict_types=1);

namespace Nvl\Pages\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Pages\Contracts\PageRequestContextResolver;
use Nvl\Pages\Data\PageRequestContextData;
use Nvl\Translatable\Exceptions\InvalidLocaleException;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

/**
 * Resolves one configured site and a validated supported content locale.
 */
final readonly class ConfiguredPageRequestContextResolver implements PageRequestContextResolver
{
    /**
     * Create the configured public request context resolver.
     */
    public function __construct(
        private LocaleRegistry $locales,
        private Repository $configuration,
        private Container $container,
    ) {}

    /**
     * Resolve the configured public site and a supported request locale.
     */
    public function resolve(Request $request): PageRequestContextData
    {
        $tenantSite = $this->configuration->get('tenancy.enabled') === true
            ? $this->container->make(TenantSiteContext::class)
            : null;
        $site = $tenantSite?->site ?? config('pages.public.default_site', 'default');

        if (! is_string($site)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $site) !== 1) {
            throw new InvalidArgumentException(
                'pages.public.default_site must be a valid page site.',
            );
        }

        $requestedLocale = $request->query('locale', $this->locales->default());
        try {
            $locale = $this->locales->assertSupported(
                is_string($requestedLocale) ? $requestedLocale : '',
            );
        } catch (InvalidLocaleException) {
            throw ValidationException::withMessages([
                'locale' => ['The selected locale is not supported.'],
            ]);
        }

        return new PageRequestContextData($site, $locale, $tenantSite);
    }
}
