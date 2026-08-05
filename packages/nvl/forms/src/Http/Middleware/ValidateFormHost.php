<?php

declare(strict_types=1);

namespace Nvl\Forms\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Nvl\Forms\Actions\Form\GetFormForRenderAction;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormCorsPolicyResolver;
use Nvl\Forms\Services\FormOriginAccessService;
use Nvl\Forms\Services\RequestOriginResolver;
use Nvl\Forms\Support\AllowedOriginExpression;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that validates form host access and rate limits.
 */
final class ValidateFormHost
{
    /**
     * Create the middleware with rate limit dependency.
     *
     * @param  GetFormForRenderAction  $getFormForRender  Public form resolver
     * @param  FormRateLimiter  $rateLimitService  Rate limit service
     * @param  RequestOriginResolver  $originResolver  Origin resolver
     * @param  FormOriginAccessService  $originAccess  Origin access resolver
     * @param  FormCorsPolicyResolver  $corsPolicy  Form and origin CORS policy resolver
     */
    public function __construct(
        private readonly GetFormForRenderAction $getFormForRender,
        private readonly FormRateLimiter $rateLimitService,
        private readonly RequestOriginResolver $originResolver,
        private readonly FormOriginAccessService $originAccess,
        private readonly FormCorsPolicyResolver $corsPolicy,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request  HTTP request instance
     * @param  Closure  $next  Next middleware
     * @return Response Middleware response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $form = $request->attributes->get('forms.resolved_form');

        if (! $form instanceof Form) {
            $formIdentifier = $request->route('form');

            if (! is_string($formIdentifier)) {
                return response()->json([
                    'error' => trans('forms::forms/messages.api.form_not_found'),
                ], 404);
            }

            try {
                $form = $this->getFormForRender->execute($formIdentifier);
            } catch (ModelNotFoundException) {
                return response()->json([
                    'error' => trans('forms::forms/messages.api.form_not_found'),
                ], 404);
            }

            $request->attributes->set('forms.resolved_form', $form);
        }

        $originHost = $this->originResolver->originHost($request);
        $originHeader = $this->originResolver->originHeader($request);

        if (($form->type ?? null) === FormType::IFRAME) {
            $request->attributes->set('forms.embeddable', true);

            $frameAncestors = $this->buildFrameAncestors($form);
            if ($frameAncestors !== null) {
                $request->attributes->set('forms.frame_ancestors', $frameAncestors);
            }
        }

        if (! $form->restrict_public_access) {
            /** @var Response $response */
            $response = $next($request);

            return $this->addCorsHeaders($response, $form, $originHost, $originHeader);
        }

        if ($originHost === null || $originHost === '') {
            return response()->json([
                'error' => trans('forms::forms/messages.error.origin_required'),
            ], 403);
        }

        if (! $this->originAccess->isOriginAllowed($form, $originHost)) {
            return response()->json([
                'error' => trans('forms::forms/shared.messages.error.origin_not_allowed', ['origin' => $originHost]),
                'origin' => $originHost,
            ], 403);
        }

        $ipAddress = $request->ip() ?? '0.0.0.0';
        $rateLimitStatus = $this->rateLimitService->getRateLimitStatus($form, $ipAddress);
        if ($rateLimitStatus['is_blocked']) {
            $response = response()->json([
                'error' => trans('forms::forms/shared.messages.error.rate_limit_exceeded'),
                'retry_after' => $rateLimitStatus['retry_after'],
            ], 429);

            return $this->addCorsHeaders($response, $form, $originHost, $originHeader);
        }

        /** @var Response $response */
        $response = $next($request);

        return $this->addCorsHeaders($response, $form, $originHost, $originHeader);
    }

    /**
     * Build a CSP `frame-ancestors` value from the form's allowed origins.
     * Returns null when the form does not restrict public access.
     *
     * @param  Form  $form  Form instance
     * @return string|null Space-separated CSP sources for frame-ancestors
     */
    private function buildFrameAncestors(Form $form): ?string
    {
        if (! $form->restrict_public_access) {
            return null;
        }

        $origins = $form->allowedOrigins()
            ->where('is_active', true)
            ->pluck('origin')
            ->all();

        $sources = ["'self'"];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            $normalized = trim($origin);
            if ($normalized === '') {
                continue;
            }

            if (! AllowedOriginExpression::isValid($normalized)) {
                continue;
            }

            $sources[] = AllowedOriginExpression::parse($normalized)->toCspSource();
        }

        $sources = array_values(array_unique($sources));

        return implode(' ', $sources);
    }

    /**
     * Add CORS headers for iframe embedding.
     *
     * @param  Response  $response  Base response
     * @param  Form  $form  Resolved form
     * @param  string|null  $originHost  Normalized origin host
     * @param  string|null  $originHeader  Raw Origin header
     * @return Response Response with CORS headers applied
     */
    private function addCorsHeaders(
        Response $response,
        Form $form,
        ?string $originHost,
        ?string $originHeader,
    ): Response {
        $settings = $this->corsPolicy->resolve($form, $originHost);

        $response->headers->remove('X-Frame-Options');
        if ($originHeader !== null && $originHeader !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $originHeader);
        }

        $response->setVary([
            'Origin',
            'Access-Control-Request-Method',
            'Access-Control-Request-Headers',
        ], false);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $settings->allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $settings->allowedHeaders));
        $response->headers->set('Access-Control-Max-Age', (string) $settings->maxAge);

        if ($settings->allowCredentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } else {
            $response->headers->remove('Access-Control-Allow-Credentials');
        }

        return $response;
    }
}
