<?php

declare(strict_types=1);

namespace Nvl\Forms\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Nvl\Forms\Actions\Form\GetFormForRenderAction;
use Nvl\Forms\Models\Form;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that blocks access to unavailable forms.
 */
final class EnsureFormIsAvailable
{
    /**
     * Create the middleware with form lookup dependency.
     *
     * @param  GetFormForRenderAction  $getForm  Action to resolve forms
     */
    public function __construct(private readonly GetFormForRenderAction $getForm) {}

    /**
     * Handle the incoming request.
     *
     * @param  Request  $request  HTTP request instance
     * @param  Closure  $next  Next middleware
     * @return Response Middleware response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $form = $request->attributes->get('forms.resolved_form');

        if (! $form instanceof Form) {
            $route = $request->route();
            $routeIdentifier = $route?->parameter('form')
                ?? $route?->parameter('formIdentifier');
            $identifier = is_string($routeIdentifier) ? $routeIdentifier : '';

            if ($identifier === '') {
                return $this->nextResponse($next, $request);
            }

            try {
                $form = $this->getForm->execute($identifier);
                $request->attributes->set('forms.resolved_form', $form);
            } catch (ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'error' => trans('forms::forms/messages.api.form_not_found'),
                ], 404);
            }
        }

        if (! $form->relationLoaded('allowedOrigins')) {
            $form->load('allowedOrigins');
        }

        if (! $form->isPubliclyAvailableNow()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => trans('forms::forms/messages.api.form_unavailable'),
                ], 403);
            }

            return redirect()->back()->withErrors([
                'error' => (string) trans('forms::forms/messages.api.form_unavailable'),
            ]);
        }

        return $this->nextResponse($next, $request);
    }

    private function nextResponse(Closure $next, Request $request): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            throw new \LogicException('Form middleware must receive an HTTP response from the next handler.');
        }

        return $response;
    }
}
