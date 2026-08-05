<?php

declare(strict_types=1);

namespace Nvl\Forms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that applies locale from query parameters.
 */
class FormsLocaleMiddleware
{
    public function __construct(
        private readonly ContentLocale $contentLocale,
        private readonly LocaleRegistry $locales,
    ) {}

    /**
     * Handle the incoming request.
     *
     * @param  Request  $request  HTTP request instance
     * @param  Closure  $next  Next middleware
     * @return Response Middleware response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $language = $request->query('lang', '');
        $lang = is_string($language) ? $language : '';
        if ($lang !== '') {
            $candidate = mb_strtolower(str_replace('_', '-', trim($lang)));
            $baseCandidate = explode('-', $candidate, 2)[0];
            $fallback = config('app.fallback_locale', 'en');
            $normalized = match (true) {
                $this->locales->supports($candidate) => $this->locales->assertSupported($candidate),
                $this->locales->supports($baseCandidate) => $this->locales->assertSupported($baseCandidate),
                is_string($fallback) && $this->locales->supports($fallback) => $this->locales->assertSupported($fallback),
                default => $this->locales->supported()[0] ?? 'en',
            };

            $this->contentLocale->set($normalized);
            App::setLocale($normalized);
            $request->setLocale($normalized);
        }

        $response = $next($request);

        if (! $response instanceof Response) {
            throw new \LogicException('Forms locale middleware must receive an HTTP response.');
        }

        return $response;
    }
}
