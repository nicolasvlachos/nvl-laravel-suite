<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvl\Seo\Services\RobotsGenerator;
use Nvl\Seo\Support\SeoConfiguration;

/**
 * Serves the configured robots policy when public routes are enabled.
 */
final readonly class RobotsController
{
    public function __construct(
        private RobotsGenerator $robots,
    ) {}

    /**
     * Serve robots.txt with validation and conditional caching headers.
     */
    public function __invoke(Request $request): Response
    {
        $content = $this->robots->generate();
        $response = response(
            $content,
            200,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
        $response->setEtag(hash('sha256', $content));
        $seconds = SeoConfiguration::nonNegativeInteger('seo.robots.cache_seconds', 3600);

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
