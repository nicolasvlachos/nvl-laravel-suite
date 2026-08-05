<?php

declare(strict_types=1);

namespace Nvl\Templates\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Templates\Actions\AssignTemplateAction;
use Nvl\Templates\Actions\CreateTemplateAction;
use Nvl\Templates\Actions\CreateTemplateVersionAction;
use Nvl\Templates\Actions\GetTemplateAction;
use Nvl\Templates\Actions\ListTemplatesAction;
use Nvl\Templates\Actions\PublishTemplateVersionAction;
use Nvl\Templates\Actions\UnassignTemplateAction;
use Nvl\Templates\Actions\UpdateTemplateAction;
use Nvl\Templates\Actions\UpdateTemplateVersionAction;
use Nvl\Templates\Data\Mutations\AssignTemplateData;
use Nvl\Templates\Data\Mutations\CreateTemplateData;
use Nvl\Templates\Data\Mutations\CreateTemplateVersionData;
use Nvl\Templates\Data\Mutations\ExpectedRevisionData;
use Nvl\Templates\Data\Mutations\UpdateTemplateData;
use Nvl\Templates\Data\Mutations\UpdateTemplateVersionData;
use Nvl\Templates\Data\TemplateAssignmentData;
use Nvl\Templates\Data\TemplateManagementData;
use Nvl\Templates\Data\TemplateVersionData;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Services\TemplateFilterSchema;
use Nvl\Templates\Support\TemplateActorFactory;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Thin opt-in management endpoints for templates and publication versions.
 */
final class TemplatesController extends Controller
{
    /**
     * List one allowlisted page of authorized templates.
     */
    public function index(
        Request $request,
        TemplateActorFactory $actors,
        QueryFilterSetFactory $filterFactory,
        TemplateFilterSchema $filterSchema,
        ListTemplatesAction $action,
    ): JsonResponse {
        $query = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }
        $templates = $action->execute(
            $filterFactory->fromHttpQuery($query, $filterSchema->make()),
            $actors->fromRequest($request),
            $request->integer(
                'per_page',
                TemplatesConfiguration::limit('per_page', 25),
            ),
        );

        return response()->json([
            'data' => array_map(
                static fn (Template $template): array => TemplateManagementData::fromModel(
                    $template,
                )->toArray(),
                $templates->items(),
            ),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    public function store(
        Request $request,
        CreateTemplateAction $action,
        TemplateActorFactory $actors,
    ): JsonResponse {
        $template = $action->execute(
            CreateTemplateData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json([
            'data' => TemplateManagementData::fromModel($template)->toArray(),
        ], 201);
    }

    public function show(
        Request $request,
        Template $template,
        TemplateActorFactory $actors,
        GetTemplateAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => TemplateManagementData::fromModel(
                $action->execute($template, $actors->fromRequest($request)),
            )->toArray(),
        ]);
    }

    public function update(
        Request $request,
        Template $template,
        TemplateActorFactory $actors,
        UpdateTemplateAction $action,
    ): JsonResponse {
        $template = $action->execute(
            $template,
            UpdateTemplateData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json([
            'data' => TemplateManagementData::fromModel($template)->toArray(),
        ]);
    }

    public function version(
        Request $request,
        Template $template,
        CreateTemplateVersionAction $action,
        TemplateActorFactory $actors,
    ): JsonResponse {
        $version = $action->execute(
            $template,
            CreateTemplateVersionData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json([
            'data' => TemplateVersionData::fromModel($version)->toArray(),
        ], 201);
    }

    public function publish(
        Request $request,
        TemplateVersion $version,
        PublishTemplateVersionAction $action,
        TemplateActorFactory $actors,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());
        $published = $action->execute(
            $version,
            $data->expectedRevision,
            $actors->fromRequest($request),
        );

        return response()->json([
            'data' => TemplateVersionData::fromModel($published)->toArray(),
        ]);
    }

    public function updateVersion(
        Request $request,
        TemplateVersion $version,
        TemplateActorFactory $actors,
        UpdateTemplateVersionAction $action,
    ): JsonResponse {
        $version = $action->execute(
            $version,
            UpdateTemplateVersionData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json([
            'data' => TemplateVersionData::fromModel($version)->toArray(),
        ]);
    }

    public function assign(
        Request $request,
        Template $template,
        TemplateActorFactory $actors,
        AssignTemplateAction $action,
    ): JsonResponse {
        $assignment = $action->execute(
            $template,
            AssignTemplateData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return response()->json([
            'data' => TemplateAssignmentData::fromModel($assignment)->toArray(),
        ]);
    }

    public function unassign(
        Request $request,
        TemplateAssignment $assignment,
        TemplateActorFactory $actors,
        UnassignTemplateAction $action,
    ): JsonResponse {
        $data = ExpectedRevisionData::validateAndCreate($request->all());

        return response()->json([
            'data' => [
                'deleted' => $action->execute(
                    $assignment,
                    $data->expectedRevision,
                    $actors->fromRequest($request),
                ),
            ],
        ]);
    }
}
