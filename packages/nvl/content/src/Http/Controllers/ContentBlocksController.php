<?php

declare(strict_types=1);

namespace Nvl\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvl\Content\Content;
use Nvl\Content\Data\ContentBlockData;
use Nvl\Content\Data\ContentPlacementData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentPlacementData;
use Nvl\Content\Data\Queries\ExpectedRevisionData;
use Nvl\Content\Http\ContentResponseData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Services\ContentOwnerRegistry;
use Nvl\Content\Support\ContentActorFactory;
use Nvl\Filterable\Http\QueryFilterSetFactory;

/**
 * Thin opt-in management API for blocks, publication, and placements.
 */
final class ContentBlocksController extends ContentController
{
    /**
     * Return every reusable semantic field preset available to the editor.
     */
    public function presets(
        Request $request,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        return response()->json([
            'data' => $this->content(
                fn () => $content->presets($actors->fromRequest($request)),
            )
                ->map(ContentResponseData::preset(...))
                ->all(),
        ]);
    }

    public function definitions(
        Request $request,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        return response()->json([
            'data' => $this->content(
                fn () => $content->definitions($actors->fromRequest($request)),
            )
                ->map(
                    ContentResponseData::definition(...),
                )
                ->all(),
        ]);
    }

    public function index(
        Request $request,
        ContentActorFactory $actors,
        QueryFilterSetFactory $filterFactory,
        Content $content,
    ): JsonResponse {
        $query = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }

        $blocks = $this->content(
            fn () => $content->blocks(
                $filterFactory->fromHttpQuery($query, ContentBlock::filterSchema()),
                $actors->fromRequest($request),
                $request->integer('per_page', 25),
            ),
        );

        return response()->json([
            'data' => array_map(
                static fn (ContentBlock $block): array => ContentResponseData::block(
                    ContentBlockData::fromModel($block),
                ),
                $blocks->items(),
            ),
            'meta' => [
                'current_page' => $blocks->currentPage(),
                'last_page' => $blocks->lastPage(),
                'per_page' => $blocks->perPage(),
                'total' => $blocks->total(),
            ],
        ]);
    }

    public function groups(
        Request $request,
        string $ownerType,
        string $ownerId,
        ContentActorFactory $actors,
        ContentOwnerRegistry $owners,
        Content $content,
    ): JsonResponse {
        $owner = $this->content(fn () => $owners->resolve($ownerType, $ownerId));

        return response()->json([
            'data' => $this->content(
                fn () => $content->groups($owner, $actors->fromRequest($request)),
            )->all(),
        ]);
    }

    public function placements(
        Request $request,
        string $ownerType,
        string $ownerId,
        string $group,
        ContentActorFactory $actors,
        ContentOwnerRegistry $owners,
        Content $content,
    ): JsonResponse {
        $owner = $this->content(fn () => $owners->resolve($ownerType, $ownerId));

        return response()->json([
            'data' => $this->content(
                fn () => $content->placements(
                    $owner,
                    $group,
                    $actors->fromRequest($request),
                ),
            )->map(
                static fn (ContentPlacement $placement): array => ContentResponseData::placement(
                    ContentPlacementData::fromModel($placement),
                ),
            )->all(),
        ]);
    }

    public function editor(
        Request $request,
        string $ownerType,
        string $ownerId,
        string $group,
        ContentActorFactory $actors,
        ContentOwnerRegistry $owners,
        Content $content,
    ): JsonResponse {
        $owner = $this->content(fn () => $owners->resolve($ownerType, $ownerId));

        return response()->json([
            'data' => ContentResponseData::editor($this->content(
                fn () => $content->editor(
                    $owner,
                    $group,
                    $actors->fromRequest($request),
                ),
            )),
        ]);
    }

    public function store(
        Request $request,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = CreateContentBlockData::validateAndCreate($request->all());
        $actor = $actors->fromRequest($request);
        $block = $this->content(
            fn () => $content->createBlock($data, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::block(ContentBlockData::fromModel($block)),
        ], 201);
    }

    public function show(
        Request $request,
        string $block,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        return response()->json([
            'data' => ContentResponseData::block(ContentBlockData::fromModel(
                $this->content(
                    fn () => $content->block($block, $actors->fromRequest($request)),
                ),
            )),
        ]);
    }

    public function update(
        Request $request,
        string $block,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = UpdateContentBlockData::validateAndCreate($request->all());
        $actor = $actors->fromRequest($request);
        $updated = $this->content(
            fn () => $content->updateBlock($block, $data, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::block(ContentBlockData::fromModel($updated)),
        ]);
    }

    public function publish(
        Request $request,
        string $block,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());
        $expectedRevision = $data->expectedRevision;
        $actor = $actors->fromRequest($request);
        $published = $this->content(
            fn () => $content->publishBlock($block, $expectedRevision, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::block(ContentBlockData::fromModel($published)),
        ]);
    }

    public function archive(
        Request $request,
        string $block,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());
        $expectedRevision = $data->expectedRevision;
        $actor = $actors->fromRequest($request);
        $archived = $this->content(
            fn () => $content->archiveBlock($block, $expectedRevision, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::block(ContentBlockData::fromModel($archived)),
        ]);
    }

    public function destroy(
        Request $request,
        string $block,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());
        $expectedRevision = $data->expectedRevision;
        $actor = $actors->fromRequest($request);
        $this->content(
            function () use ($actor, $block, $content, $expectedRevision): bool {
                $content->deleteBlock($block, $expectedRevision, $actor);

                return true;
            },
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function restore(
        Request $request,
        string $block,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());
        $expectedRevision = $data->expectedRevision;
        $actor = $actors->fromRequest($request);
        $restored = $this->content(
            fn () => $content->restoreBlock($block, $expectedRevision, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::block(ContentBlockData::fromModel($restored)),
        ]);
    }

    public function place(
        Request $request,
        string $ownerType,
        string $ownerId,
        string $group,
        string $block,
        ContentActorFactory $actors,
        ContentOwnerRegistry $owners,
        Content $content,
    ): JsonResponse {
        $data = PlaceContentBlockData::validateAndCreate($request->all());
        $actor = $actors->fromRequest($request);
        $owner = $this->content(fn () => $owners->resolve($ownerType, $ownerId));
        $placement = $this->content(
            fn () => $content->place($block, $owner, $group, $data, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::placement(ContentPlacementData::fromModel($placement)),
        ], 201);
    }

    public function updatePlacement(
        Request $request,
        string $placement,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = UpdateContentPlacementData::validateAndCreate($request->all());
        $actor = $actors->fromRequest($request);
        $updated = $this->content(
            fn () => $content->updatePlacement($placement, $data, $actor),
        );

        return response()->json([
            'data' => ContentResponseData::placement(ContentPlacementData::fromModel($updated)),
        ]);
    }

    public function destroyPlacement(
        Request $request,
        string $placement,
        ContentActorFactory $actors,
        Content $content,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());
        $expectedRevision = $data->expectedRevision;
        $actor = $actors->fromRequest($request);
        $this->content(
            function () use ($actor, $content, $expectedRevision, $placement): bool {
                $content->deletePlacement($placement, $expectedRevision, $actor);

                return true;
            },
        );

        return response()->json(['data' => ['deleted' => true]]);
    }
}
