<?php

declare(strict_types=1);

namespace Nvl\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvl\Content\Content;
use Nvl\Content\Data\Queries\ContentLocaleQueryData;
use Nvl\Content\Http\ContentResponseData;
use Nvl\Content\Services\ContentLocalePolicy;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Support\ContentActorFactory;

/**
 * Optional public-safe headless composition endpoint.
 */
final class ContentCompositionController extends ContentController
{
    public function show(
        Request $request,
        string $ownerType,
        string $ownerId,
        string $group,
        ContentActorFactory $actors,
        ContentOwnerRegistry $owners,
        Content $content,
        ContentLocalePolicy $locales,
    ): JsonResponse {
        $data = ContentLocaleQueryData::validateAndCreate($request->all());
        $locale = $data->locale ?? $locales->current();
        $owner = $this->content(fn () => $owners->resolve($ownerType, $ownerId));
        $composition = $this->content(
            fn () => $content->render(
                $owner,
                $group,
                $locale,
                $actors->fromRequest($request),
                publicOnly: true,
            ),
        );

        return response()->json(['data' => ContentResponseData::composition($composition)]);
    }

    public function preview(
        Request $request,
        string $ownerType,
        string $ownerId,
        string $group,
        ContentActorFactory $actors,
        ContentOwnerRegistry $owners,
        Content $content,
        ContentLocalePolicy $locales,
    ): JsonResponse {
        $data = ContentLocaleQueryData::validateAndCreate($request->all());
        $locale = $data->locale ?? $locales->current();
        $owner = $this->content(fn () => $owners->resolve($ownerType, $ownerId));
        $composition = $this->content(
            fn () => $content->render(
                $owner,
                $group,
                $locale,
                $actors->fromRequest($request),
                publicOnly: false,
            ),
        );

        return response()->json(['data' => ContentResponseData::composition($composition)]);
    }
}
