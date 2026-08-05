<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Support\Facades\DB;
use Nvl\Seo\Data\ResolvedRedirectData;
use Nvl\Seo\Models\SeoRedirect;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoScope;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Resolves active redirects and records bounded hit metadata.
 */
final class SeoRedirectResolver
{
    /**
     * Create the redirect resolver.
     */
    public function __construct(
        private readonly LocaleRegistry $locales,
        private readonly SeoRedirectLookup $redirects,
    ) {}

    /**
     * Resolve an active redirect for one normalized source identity.
     */
    public function resolve(
        string $source,
        ?string $locale = null,
        ?string $scope = null,
    ): ?ResolvedRedirectData {
        if (! (bool) config('seo.redirects.enabled', true)) {
            return null;
        }

        $scope = SeoScope::normalize($scope);
        $locale = $locale === null ? null : $this->locales->assertSupported($locale);
        $source = SeoPath::normalize($source) ?? '/';
        $redirect = $this->redirects->findActive($scope, $locale, $source);

        if (! $redirect instanceof SeoRedirect) {
            return null;
        }

        if ((bool) config('seo.redirects.record_hits', true)) {
            SeoRedirect::query()->whereKey($redirect->id)->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_hit_at' => now(),
            ]);
        }

        return new ResolvedRedirectData(
            id: $redirect->id,
            source: $redirect->source_path,
            target: $redirect->target,
            statusCode: $redirect->status_code,
        );
    }
}
