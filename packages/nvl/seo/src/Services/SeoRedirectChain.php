<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Nvl\Seo\Exceptions\SeoRedirectLoopException;
use Nvl\Seo\Support\HttpUrl;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoRedirectTarget;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Detects loops and flattens relative redirect chains before persistence.
 */
final class SeoRedirectChain
{
    /**
     * Create the redirect-chain resolver.
     */
    public function __construct(
        private readonly LocaleRegistry $locales,
        private readonly SeoRedirectLookup $redirects,
        private readonly AbsoluteUrl $urls,
    ) {}

    /**
     * Flatten one redirect target while rejecting active non-expired loops.
     */
    public function flatten(
        string $scope,
        ?string $locale,
        string $source,
        string $target,
        ?string $ignoreId = null,
    ): string {
        $locale = $locale === null ? null : $this->locales->assertSupported($locale);
        $source = SeoPath::normalize($source) ?? '/';
        $target = SeoRedirectTarget::normalize($target);
        $preservedTarget = null;

        if (HttpUrl::isAbsolute($target)) {
            $targetPath = $this->sameSitePath($target);

            if ($targetPath === null) {
                return $target;
            }

            $preservedTarget = $target;
        } else {
            $targetPath = $this->applicationPath($target);

            if ($targetPath !== $target) {
                $preservedTarget = $target;
            }
        }

        $seen = [$source => true];
        $maximum = SeoConfiguration::positiveInteger('seo.redirects.maximum_chain_length', 20);

        for ($depth = 0; $depth < $maximum; $depth++) {
            if (isset($seen[$targetPath])) {
                throw new SeoRedirectLoopException(
                    "SEO redirect [{$source}] introduces a loop through [{$targetPath}].",
                );
            }

            $seen[$targetPath] = true;
            $next = $this->redirects->findActive(
                $scope,
                $locale,
                $targetPath,
                $ignoreId,
            );

            if ($next === null) {
                return $preservedTarget ?? $targetPath;
            }

            $nextTarget = SeoRedirectTarget::normalize($next->target);

            if (HttpUrl::isAbsolute($nextTarget)) {
                $internalPath = $this->sameSitePath($nextTarget);

                if ($internalPath === null) {
                    return $preservedTarget ?? $nextTarget;
                }

                $preservedTarget ??= $nextTarget;
                $targetPath = $internalPath;

                continue;
            }

            $nextPath = $this->applicationPath($nextTarget);

            if ($nextPath !== $nextTarget) {
                $preservedTarget ??= $nextTarget;
            }

            $targetPath = $nextPath;
        }

        throw new SeoRedirectLoopException(
            "SEO redirect [{$source}] exceeds the configured chain limit.",
        );
    }

    /**
     * Return the normalized path component of an application target.
     */
    private function applicationPath(string $target): string
    {
        $path = parse_url($target, PHP_URL_PATH);

        return SeoPath::normalize(is_string($path) ? $path : '/') ?? '/';
    }

    /**
     * Return an application-relative path for an absolute URL on this site.
     */
    private function sameSitePath(string $target): ?string
    {
        $siteUrl = $this->urls->resolve('/');

        if ($siteUrl === null || ! HttpUrl::hasSameOrigin($target, $siteUrl)) {
            return null;
        }

        $targetPath = parse_url($target, PHP_URL_PATH);
        $sitePath = parse_url($siteUrl, PHP_URL_PATH);

        if (! is_string($targetPath) || ! is_string($sitePath)) {
            return null;
        }

        $siteDirectory = rtrim($sitePath, '/');

        if ($siteDirectory !== '') {
            if ($targetPath === $siteDirectory) {
                return '/';
            }

            if (! str_starts_with($targetPath, $siteDirectory.'/')) {
                return null;
            }

            $targetPath = substr($targetPath, strlen($siteDirectory));
        }

        return SeoPath::normalize($targetPath) ?? '/';
    }
}
