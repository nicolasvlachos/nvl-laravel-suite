<?php

declare(strict_types=1);

namespace Nvl\Templates\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Templates\Actions\GetTemplateRenderAction;
use Nvl\Templates\Actions\ListTemplateRendersAction;
use Nvl\Templates\Actions\QueueTemplateRenderAction;
use Nvl\Templates\Actions\RenderStoredTemplateAction;
use Nvl\Templates\Data\Mutations\RenderTemplateData;
use Nvl\Templates\Data\TemplateRenderData;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Services\TemplateRenderFilterSchema;
use Nvl\Templates\Services\TemplateResponseFactory;
use Nvl\Templates\Support\TemplateActorFactory;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Opt-in synchronous and asynchronous render endpoints.
 */
final class TemplateRenderController extends Controller
{
    /**
     * List authorized durable render history.
     */
    public function index(
        Request $request,
        TemplateActorFactory $actors,
        QueryFilterSetFactory $filterFactory,
        TemplateRenderFilterSchema $filterSchema,
        ListTemplateRendersAction $action,
    ): JsonResponse {
        $query = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }

        $renders = $action->execute(
            $filterFactory->fromHttpQuery($query, $filterSchema->make()),
            $actors->fromRequest($request),
            $request->integer(
                'per_page',
                TemplatesConfiguration::limit('per_page', 25),
            ),
        );

        return $this->protectedJson([
            'data' => array_map(
                static fn (TemplateRender $render): array => TemplateRenderData::fromModel(
                    $render,
                )->toArray(),
                $renders->items(),
            ),
            'meta' => [
                'current_page' => $renders->currentPage(),
                'last_page' => $renders->lastPage(),
                'per_page' => $renders->perPage(),
                'total' => $renders->total(),
            ],
        ]);
    }

    /**
     * Show one authorized durable render status.
     */
    public function show(
        Request $request,
        TemplateRender $render,
        TemplateActorFactory $actors,
        GetTemplateRenderAction $action,
    ): JsonResponse {
        return $this->protectedJson([
            'data' => TemplateRenderData::fromModel(
                $action->execute($render, $actors->fromRequest($request)),
            )->toArray(),
        ]);
    }

    /**
     * Render one stored template synchronously.
     */
    public function render(
        Request $request,
        string $template,
        RenderStoredTemplateAction $action,
        TemplateActorFactory $actors,
        TemplateResponseFactory $responses,
    ): Response {
        $data = RenderTemplateData::validateAndCreate($request->all());
        $result = $action->execute(
            $template,
            $data,
            $actors->fromRequest($request),
        );

        return $data->download
            ? $responses->download($result)
            : $responses->inline($result);
    }

    /**
     * Queue one preflighted durable render.
     */
    public function queue(
        Request $request,
        string $template,
        QueueTemplateRenderAction $action,
        TemplateActorFactory $actors,
    ): JsonResponse {
        $render = $action->execute(
            $template,
            RenderTemplateData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return $this->protectedJson([
            'data' => [
                'id' => $render->id,
                'status' => $render->status->value,
            ],
        ], 202);
    }

    /**
     * Return private render metadata without permitting intermediary storage.
     *
     * @param  array<string, mixed>  $payload
     */
    private function protectedJson(array $payload, int $status = 200): JsonResponse
    {
        $response = response()->json($payload, $status);
        $response->headers->set(
            'Cache-Control',
            'private, no-store, no-cache, must-revalidate',
        );
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
