<?php

declare(strict_types=1);

namespace Nvl\Forms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Nvl\Data\Data\PaginatedCollection;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Forms\Actions\Form\BuildFormShowPayloadAction;
use Nvl\Forms\Actions\Form\CreateFormAction;
use Nvl\Forms\Actions\Form\DeleteFormAction;
use Nvl\Forms\Actions\Form\DuplicateFormAction;
use Nvl\Forms\Actions\Form\GetFormSelectOptionsAction;
use Nvl\Forms\Actions\Form\GetFormSuggestionsAction;
use Nvl\Forms\Actions\Form\ListFormsAction;
use Nvl\Forms\Actions\Form\SearchFormsAction;
use Nvl\Forms\Actions\Form\UpdateFormAction;
use Nvl\Forms\Data\FormPayload;
use Nvl\Forms\Data\FormSearchFilter;
use Nvl\Forms\Data\FormSelectOption;
use Nvl\Forms\Data\FormSuggestion;
use Nvl\Forms\Data\FormSuggestions;
use Nvl\Forms\Data\Mutations\DuplicateFormData;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormResponseCode;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Symfony\Component\HttpFoundation\Response;

/**
 * API controller for forms search and autocomplete functionality.
 */
final class FormsApiController extends Controller
{
    /**
     * Display a paginated listing of forms.
     *
     * @param  Request  $request  Incoming listing request
     * @param  ListFormsAction  $action  Forms listing action
     * @return JsonResponse Paginated form rows
     */
    public function index(
        Request $request,
        ListFormsAction $action,
        QueryFilterSetFactory $filterFactory,
    ): JsonResponse {
        Gate::authorize('viewAny', Form::class);

        $perPage = $request->integer('per_page', 20) ?: 20;
        $forms = $action->execute(
            true,
            $perPage,
            $filterFactory->fromHttpQuery(
                $this->queryParameters($request),
                (new Form)->filterSchema(),
            ),
        );

        if (! $forms instanceof LengthAwarePaginator) {
            throw new FormException('Forms index expects a paginator payload.');
        }

        return response()->json([
            'data' => [
                'forms' => PaginatedCollection::fromPaginator($forms, FormPayload::class)->toArray(),
            ],
        ], 200);
    }

    /**
     * Store a newly created form.
     *
     * @param  Request  $request  Incoming create request
     * @param  CreateFormAction  $action  Form creation action
     * @return JsonResponse Created form payload
     */
    public function store(Request $request, CreateFormAction $action): JsonResponse
    {
        Gate::authorize('create', Form::class);

        $form = $action->execute(
            MutateFormPayload::validateForCreate(
                $this->normalizePayloadArray($request->all()),
            ),
            $request->user(),
        );

        return response()->json(['data' => FormPayload::fromModel($form)->toArray(), 'code' => FormResponseCode::Created->value], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParameters(Request $request): array
    {
        $parameters = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Display the specified form with derived show-page state.
     *
     * @param  Request  $request  Incoming show request
     * @param  Form  $form  Route-bound form
     * @param  BuildFormShowPayloadAction  $action  Show payload action
     * @return JsonResponse Form detail payload
     */
    public function show(Request $request, Form $form, BuildFormShowPayloadAction $action): JsonResponse
    {
        Gate::authorize('view', $form);

        $origin = $request->header('Origin') ?? $request->header('Referer');

        return response()->json(['data' => $action->execute(
            $form,
            false,
            $origin,
            $request->ip() ?? '0.0.0.0',
            $request->userAgent(),
            $request->hasSession() ? $request->session()->getId() : null,
            $request->user(),
        )], 200);
    }

    /**
     * Update the specified form.
     *
     * @param  Request  $request  Incoming update request
     * @param  Form  $form  Route-bound form
     * @param  UpdateFormAction  $action  Form update action
     * @return JsonResponse Updated form payload
     */
    public function update(Request $request, Form $form, UpdateFormAction $action): JsonResponse
    {
        Gate::authorize('update', $form);

        $form = $action->execute(
            $form,
            MutateFormPayload::validateForUpdate(
                $this->normalizePayloadArray($request->all()),
                $form->id,
            ),
            $request->user(),
        );

        return response()->json(['data' => FormPayload::fromModel($form)->toArray(), 'code' => FormResponseCode::Updated->value], 200);
    }

    /**
     * Remove the specified form.
     *
     * @param  Request  $request  Incoming delete request
     * @param  Form  $form  Route-bound form
     * @param  DeleteFormAction  $action  Form deletion action
     * @return JsonResponse Delete result payload
     */
    public function destroy(Request $request, Form $form, DeleteFormAction $action): JsonResponse
    {
        Gate::authorize('delete', $form);

        return response()->json(['data' => ['deleted' => $action->execute($form, $request->user())], 'code' => FormResponseCode::Deleted->value], 200);
    }

    /**
     * Duplicate the specified form.
     *
     * @param  Request  $request  Incoming duplicate request
     * @param  Form  $form  Route-bound form
     * @param  DuplicateFormAction  $action  Form duplication action
     * @return JsonResponse Duplicated form payload
     */
    public function duplicate(Request $request, Form $form, DuplicateFormAction $action): JsonResponse
    {
        Gate::authorize('duplicate', $form);

        $data = DuplicateFormData::validateAndCreate($request->all());
        $name = $data->name;

        $duplicatedForm = $action->execute(
            $form,
            is_string($name) && $name !== '' ? $name : null,
            $request->user(),
        );

        return response()->json(['data' => FormPayload::fromModel($duplicatedForm)->toArray(), 'code' => FormResponseCode::Duplicated->value], 201);
    }

    /**
     * Get form suggestions for autocomplete/select.
     *
     * @param  FormSuggestions  $data  Validated suggestion parameters
     * @param  GetFormSuggestionsAction  $action  Action to get form suggestions
     * @return JsonResponse Suggestions response
     */
    public function suggestions(FormSuggestions $data, GetFormSuggestionsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Form::class);

        $limit = max(1, min(50, is_int($data->limit) ? $data->limit : 10));
        $forms = $action->execute($data->q, $limit);

        $dtos = $forms->map(static fn (Form $form) => FormSuggestion::fromModel($form));

        return response()->json(['data' => FormSuggestion::collect($dtos)->toArray()], 200);
    }

    /**
     * Search forms with filters.
     *
     * @param  FormSearchFilter  $data  Validated search parameters
     * @param  SearchFormsAction  $action  Action to search forms
     * @return JsonResponse Search results response
     */
    public function search(FormSearchFilter $data, SearchFormsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Form::class);

        $payload = $this->normalizePayloadArray($data->toArray());

        $result = $action->execute($payload);

        return response()->json(['data' => [
            'items' => $result->forms,
            'total' => $result->total,
        ]], 200);
    }

    /**
     * Get select options for forms.
     *
     * @param  FormSelectOption  $data  Validated select parameters
     * @param  GetFormSelectOptionsAction  $action  Action to get form select options
     * @return JsonResponse Select options response
     */
    public function select(FormSelectOption $data, GetFormSelectOptionsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Form::class);

        $options = $action->execute($this->normalizePayloadArray($data->toArray()));

        return response()->json(['data' => $options], 200);
    }

    /**
     * Normalize Data::toArray payloads to string-keyed arrays.
     *
     * @param  array<array-key, mixed>  $payload  Raw DTO payload
     * @return array<string, mixed> Normalized payload
     */
    private function normalizePayloadArray(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
