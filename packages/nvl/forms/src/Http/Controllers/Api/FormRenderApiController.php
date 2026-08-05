<?php

declare(strict_types=1);

namespace Nvl\Forms\Http\Controllers\Api;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nvl\Forms\Actions\Form\GetFormForRenderAction;
use Nvl\Forms\Actions\Form\GetFormValidationSchemaAction;
use Nvl\Forms\Actions\Form\HandleFormSubmissionErrorAction;
use Nvl\Forms\Actions\Form\HandlePublicFormSubmissionAction;
use Nvl\Forms\Actions\Form\TransformFormDataForRenderAction;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\PublicFormSubmissionResponseMapper;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Services\RequestOriginResolver;
use Nvl\Forms\Support\FormRenderDataRegistry;
use Nvl\Forms\Support\FormsConfiguration;
use Nvl\Forms\Support\FormSubmissionContext;
use Nvl\Support\Exceptions\BusinessException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * API controller for rendering forms in iframes and handling submissions.
 */
final class FormRenderApiController extends Controller
{
    public function __construct(private readonly Application $application) {}

    /**
     * Show form structure for iframe rendering.
     *
     * Merges additional render data and translations from registered providers
     * into the response payload.
     *
     * @param  GetFormForRenderAction  $getForm  Action to retrieve form for rendering
     * @param  TransformFormDataForRenderAction  $transformData  Action to transform form data
     * @param  PublicFormTokenService  $tokenService  Service that issues public tokens
     * @param  FormRenderDataRegistry  $renderDataRegistry  Registry for form-specific render data
     * @param  Request  $request  Current HTTP request
     * @param  string  $formIdentifier  Form ID or handle
     * @return JsonResponse Form rendering response
     */
    public function show(
        GetFormForRenderAction $getForm,
        TransformFormDataForRenderAction $transformData,
        PublicFormTokenService $tokenService,
        FormRenderDataRegistry $renderDataRegistry,
        Request $request,
        string $formIdentifier
    ): JsonResponse {
        try {
            $form = $getForm->execute($formIdentifier);
            $renderData = $transformData->execute($form);

            $additionalData = $renderDataRegistry->getData($form, $request);
            $additionalTranslations = $renderDataRegistry->getTranslations($form);

            return response()->json(array_merge([
                'success' => true,
                'data' => $renderData->toArray(),
                'csrf_token' => csrf_token(),
                'public_token' => $tokenService->issue(
                    $form,
                    now()->addMinutes(FormsConfiguration::positiveInteger(
                        'forms.public.token_ttl_minutes',
                        15,
                    )),
                ),
                'extension_translations' => $additionalTranslations !== [] ? $additionalTranslations : null,
            ], $additionalData));

        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => trans('forms::forms/messages.api.form_not_found'),
            ], 404);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'error' => trans('forms::forms/messages.api.form_load_error'),
                'message' => $this->application->environment('local')
                    ? $e->getMessage()
                    : trans('forms::forms/messages.api.form_load_error_detail'),
            ], 500);
        }
    }

    /**
     * Handle form submission from iframe.
     *
     * Catches BusinessException from custom handlers
     * and maps them to field-level errors via the shared response mapper,
     * matching the PublicFormsController error handling contract.
     *
     * @param  SubmitFormPayload  $data  Validated submission data
     * @param  HandlePublicFormSubmissionAction  $handleSubmission  Submission orchestration action
     * @param  HandleFormSubmissionErrorAction  $handleError  Action to handle submission errors
     * @param  GetFormForRenderAction  $getForm  Action to resolve form for error mapping
     * @param  PublicFormSubmissionResponseMapper  $responseMapper  Mapper for reusable warning and business-error response data
     * @param  RequestOriginResolver  $originResolver  Trusted request-origin resolver
     * @param  Request  $request  Original request for header extraction
     * @param  string  $formIdentifier  Form ID or handle
     * @return JsonResponse Submission response
     */
    public function submit(
        SubmitFormPayload $data,
        HandlePublicFormSubmissionAction $handleSubmission,
        HandleFormSubmissionErrorAction $handleError,
        GetFormForRenderAction $getForm,
        PublicFormSubmissionResponseMapper $responseMapper,
        RequestOriginResolver $originResolver,
        Request $request,
        string $formIdentifier
    ): JsonResponse {
        try {
            $submission = $handleSubmission->execute(
                formIdentifier: $formIdentifier,
                data: $data,
                context: FormSubmissionContext::fromRequest($request, $originResolver),
                enforceSubmissionProtection: true,
            );
            $warning = $responseMapper->warning($submission);

            $response = [
                'success' => true,
                'message' => trans('forms::forms/messages.api.form_submitted'),
                'data' => [
                    'entry_id' => $submission->entryId,
                    'form_name' => $submission->form->displayName(),
                    'submitted_at' => $submission->submittedAt->format('Y-m-d H:i:s'),
                ],
            ];

            if ($warning !== null) {
                $response['warning'] = $warning;
            }

            return response()->json($response, 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => trans('forms::forms/messages.api.validation_failed'),
                'message' => trans('forms::forms/messages.api.validation_failed_detail'),
                'errors' => $e->errors(),
            ], 422);

        } catch (BusinessException $e) {
            $form = $getForm->execute($formIdentifier);
            $mappedErrors = $responseMapper->businessErrors($form, $e);

            if (! array_key_exists('error', $mappedErrors) || count($mappedErrors) > 1) {
                return response()->json([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'errors' => $mappedErrors,
                ], 422);
            }

            $mappedError = $mappedErrors['error'];

            return response()->json([
                'success' => false,
                'error' => is_string($mappedError) ? $mappedError : $e->getMessage(),
            ], 422);

        } catch (TooManyRequestsHttpException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : trans('forms::forms/shared.messages.error.rate_limit_exceeded'),
            ], 429);

        } catch (FormSubmissionRejectionException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : trans('forms::forms/messages.api.submission_failed'),
            ], $e->statusCode());

        } catch (Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => trans('forms::forms/messages.api.submission_failed'),
                'message' => $handleError->execute($e),
            ], 400);
        }
    }

    /**
     * Handle preflight OPTIONS requests for CORS.
     *
     * @param  GetFormForRenderAction  $getForm  Action to retrieve form
     * @param  string  $formIdentifier  Form ID or handle
     * @return JsonResponse CORS preflight response
     */
    public function options(GetFormForRenderAction $getForm, string $formIdentifier): JsonResponse
    {
        try {
            $getForm->execute($formIdentifier);

            return response()->json([
                'success' => true,
                'methods' => ['GET', 'POST', 'OPTIONS'],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => trans('forms::forms/messages.api.form_not_found'),
            ], 404);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'error' => trans('forms::forms/messages.api.form_load_error'),
            ], 500);
        }
    }

    /**
     * Get form validation schema for client-side validation.
     *
     * @param  GetFormValidationSchemaAction  $getSchema  Action to generate validation schema
     * @param  string  $formIdentifier  Form ID or handle
     * @return JsonResponse Validation schema response
     */
    public function schema(GetFormValidationSchemaAction $getSchema, string $formIdentifier): JsonResponse
    {
        try {
            $schema = $getSchema->execute($formIdentifier);

            return response()->json([
                'success' => true,
                'data' => $schema->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => trans('forms::forms/messages.api.form_not_found'),
            ], 404);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'error' => trans('forms::forms/messages.api.schema_load_error'),
                'message' => $this->application->environment('local')
                    ? $e->getMessage()
                    : trans('forms::forms/messages.api.error'),
            ], 500);
        }
    }
}
