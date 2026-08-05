<?php

declare(strict_types=1);

namespace Nvl\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nvl\Pages\Actions\GetNavigationAction;
use Nvl\Pages\Contracts\PageRequestContextResolver;
use Nvl\Pages\Support\PageActorFactory;

/**
 * Thin optional HTTP adapter for one localized public navigation tree.
 */
final class PublicNavigationController extends Controller
{
    /**
     * Return public navigation using trusted site and locale context.
     */
    public function __invoke(
        Request $request,
        GetNavigationAction $action,
        PageActorFactory $actors,
        PageRequestContextResolver $contextResolver,
    ): JsonResponse {
        $context = $contextResolver->resolve($request);

        return response()->json([
            'data' => $action->execute(
                $context->site,
                $context->locale,
                $actors->fromRequest($request),
            )->toArray(),
        ]);
    }
}
