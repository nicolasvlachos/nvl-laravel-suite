<?php

declare(strict_types=1);

namespace Nvl\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Pages\Actions\CreatePageAction;
use Nvl\Pages\Actions\DeletePageAction;
use Nvl\Pages\Actions\GetPageAction;
use Nvl\Pages\Actions\ListPagesAction;
use Nvl\Pages\Actions\MovePageAction;
use Nvl\Pages\Actions\PreviewPageAction;
use Nvl\Pages\Actions\RestorePageAction;
use Nvl\Pages\Actions\UpdatePageAction;
use Nvl\Pages\Data\Mutations\CreatePageData;
use Nvl\Pages\Data\Mutations\DeletePageData;
use Nvl\Pages\Data\Mutations\MovePageData;
use Nvl\Pages\Data\Mutations\RestorePageData;
use Nvl\Pages\Data\Mutations\UpdatePageData;
use Nvl\Pages\Data\PageData;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Services\PageFilterSchema;
use Nvl\Pages\Support\PageActorFactory;
use Nvl\Translatable\Services\LocaleRegistry;

/**
 * Thin opt-in management endpoints over page Actions and DTOs.
 */
final class PagesManagementController extends Controller
{
    /**
     * Return a filtered site-scoped page management paginator.
     */
    public function index(
        Request $request,
        PageActorFactory $actors,
        QueryFilterSetFactory $filterFactory,
        PageFilterSchema $filterSchema,
        ListPagesAction $action,
    ): JsonResponse {
        $request->validate([
            'site' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
        ]);
        $query = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }

        unset($query['site'], $query['per_page']);
        $pages = $action->execute(
            $filterFactory->fromHttpQuery($query, $filterSchema->make()),
            $request->string('site')->toString(),
            $actors->fromRequest($request),
            $request->integer('per_page', 25),
        );

        return response()->json([
            'data' => array_map(
                static fn (Page $page): array => PageData::fromModel($page)->toArray(),
                $pages->items(),
            ),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
            ],
        ]);
    }

    /**
     * Create one page from the validated mutation DTO.
     */
    public function store(
        Request $request,
        CreatePageAction $action,
        PageActorFactory $actors,
    ): JsonResponse {
        $page = $action->execute(
            CreatePageData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json(['data' => PageData::fromModel($page)->toArray()], 201);
    }

    /**
     * Return one authorized page management projection.
     */
    public function show(
        Request $request,
        Page $page,
        GetPageAction $action,
        PageActorFactory $actors,
    ): JsonResponse {
        return response()->json([
            'data' => PageData::fromModel(
                $action->execute($page, $actors->fromRequest($request)),
            )->toArray(),
        ]);
    }

    /**
     * Replace one page from the validated mutation DTO.
     */
    public function update(
        Request $request,
        Page $page,
        UpdatePageAction $action,
        PageActorFactory $actors,
    ): JsonResponse {
        $page = $action->execute(
            $page,
            UpdatePageData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json(['data' => PageData::fromModel($page)->toArray()]);
    }

    /**
     * Reparent or reorder one page from the validated mutation DTO.
     */
    public function move(
        Request $request,
        Page $page,
        MovePageAction $action,
        PageActorFactory $actors,
    ): JsonResponse {
        $page = $action->execute(
            $page,
            MovePageData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json(['data' => PageData::fromModel($page)->toArray()]);
    }

    /**
     * Soft-delete one page using its exact revision.
     */
    public function destroy(
        Request $request,
        Page $page,
        DeletePageAction $action,
        PageActorFactory $actors,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'deleted' => $action->execute(
                    $page,
                    DeletePageData::validateAndCreate($request->all()),
                    $actors->fromRequest($request),
                ),
            ],
        ]);
    }

    /**
     * Restore one soft-deleted page using its exact revision.
     */
    public function restore(
        Request $request,
        string $page,
        RestorePageAction $action,
        PageActorFactory $actors,
    ): JsonResponse {
        $restored = $action->execute(
            $page,
            RestorePageData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json(['data' => PageData::fromModel($restored)->toArray()]);
    }

    /**
     * Resolve an authorized preview for a draft or public page.
     */
    public function preview(
        Request $request,
        string $path,
        PreviewPageAction $action,
        PageActorFactory $actors,
        LocaleRegistry $locales,
    ): JsonResponse {
        $request->validate([
            'site' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'locale' => ['required', 'string', 'max:35', Rule::in($locales->supported())],
        ]);
        $site = $request->string('site')->toString();
        $locale = $locales->assertSupported($request->string('locale')->toString());

        return response()->json([
            'data' => $action->execute(
                $path,
                $site,
                $locale,
                $actors->fromRequest($request),
            )->toArray(),
        ]);
    }
}
