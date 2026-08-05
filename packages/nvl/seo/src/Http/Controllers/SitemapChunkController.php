<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Controllers;

use Illuminate\Http\Response;
use Nvl\Seo\Http\Requests\SitemapRequest;
use Nvl\Seo\Services\SitemapGenerator;
use Nvl\Seo\Support\SeoConfiguration;
use OutOfBoundsException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one generated sitemap chunk.
 */
final readonly class SitemapChunkController
{
    public function __construct(private SitemapGenerator $sitemaps) {}

    /**
     * Serve one sitemap chunk with validation and conditional caching headers.
     */
    public function __invoke(SitemapRequest $request, int|string $chunk): Response
    {
        try {
            $xml = $this->sitemaps->generateChunk(
                (int) $chunk,
                $request->scope(),
            );
        } catch (OutOfBoundsException) {
            throw new NotFoundHttpException;
        }

        $response = response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', $xml));
        $seconds = SeoConfiguration::nonNegativeInteger('seo.sitemap.cache_seconds', 3600);

        if ($seconds > 0) {
            $response->setPublic();
            $response->setMaxAge($seconds);
        } else {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        $response->isNotModified($request);

        return $response;
    }
}
