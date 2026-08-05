<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Closure;
use Nvl\Forms\Actions\FormEntry\CreateFormEntryAction;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Results\FormSubmissionResult;
use Nvl\Forms\Services\CustomFormRegistry;
use Nvl\Forms\Services\CustomSubmissionReceiptService;
use Nvl\Forms\Services\EntryCallbackRegistry;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Support\CustomFormGuardResult;
use Nvl\Forms\Support\FormSubmissionContext;
use Throwable;

/**
 * Orchestrates the public submission workflow for both web and API entry points.
 *
 * The approved action composition keeps protection, custom-handler guards,
 * entry persistence, analytics, callbacks, and durable receipts behind one
 * transport-neutral use-case boundary.
 */
final class HandlePublicFormSubmissionAction
{
    /**
     * Create the action with submission dependencies.
     *
     * @param  GetFormForRenderAction  $getForm  Form resolver action
     * @param  ValidateFormSubmissionProtectionAction  $validateSubmissionProtection  CSRF/public token validator
     * @param  GuardCustomFormSubmissionAction  $guardCustomSubmission  Custom submission guard
     * @param  PrepareFormSubmissionDataAction  $prepareData  Entry payload normalizer
     * @param  CreateFormEntryAction  $createEntry  Entry persistence action
     * @param  RecordFormSubmissionAction  $recordFormSubmission  Submission analytics recorder
     * @param  RecordFormAnalyticAction  $recordFormAnalytic  Analytics recorder
     * @param  CustomFormRegistry  $customRegistry  Custom handler registry
     * @param  EntryCallbackRegistry  $entryCallbacks  Post-submit callback registry
     * @param  CustomSubmissionReceiptService  $customReceipts  Durable custom-handler claim service
     * @param  PublicFormTokenService  $tokenService  Public token metadata service
     */
    public function __construct(
        private readonly GetFormForRenderAction $getForm,
        private readonly ValidateFormSubmissionProtectionAction $validateSubmissionProtection,
        private readonly GuardCustomFormSubmissionAction $guardCustomSubmission,
        private readonly PrepareFormSubmissionDataAction $prepareData,
        private readonly CreateFormEntryAction $createEntry,
        private readonly RecordFormSubmissionAction $recordFormSubmission,
        private readonly RecordFormAnalyticAction $recordFormAnalytic,
        private readonly CustomFormRegistry $customRegistry,
        private readonly EntryCallbackRegistry $entryCallbacks,
        private readonly CustomSubmissionReceiptService $customReceipts,
        private readonly PublicFormTokenService $tokenService,
    ) {}

    /**
     * Execute the public submission flow.
     *
     * @param  Form|string  $formIdentifier  Form model, UUID, or handle
     * @param  SubmitFormPayload  $data  Validated submission payload
     * @param  FormSubmissionContext  $context  Request-derived submission context
     * @param  bool  $enforceSubmissionProtection  Whether to enforce CSRF/public token checks
     * @return FormSubmissionResult Submission result payload
     *
     * @throws Throwable
     */
    public function execute(
        Form|string $formIdentifier,
        SubmitFormPayload $data,
        FormSubmissionContext $context,
        bool $enforceSubmissionProtection = true
    ): FormSubmissionResult {
        $form = $this->getForm->execute($formIdentifier);

        if (! $form->isPubliclyAvailableNow()) {
            throw new FormSubmissionRejectionException(
                message: (string) trans('forms::forms/messages.api.form_unavailable'),
                statusCode: 403,
            );
        }

        if ($enforceSubmissionProtection) {
            $this->validateSubmissionProtection->execute($form, $context);
        }

        if (($form->resolvement ?? Resolvement::ENTRIES) === Resolvement::CUSTOM) {
            return $this->executeCustomSubmission($form, $data, $context);
        }

        return $this->executeEntrySubmission($form, $data, $context);
    }

    /**
     * Execute a submission through a custom handler.
     *
     * @param  Form  $form  Target form
     * @param  SubmitFormPayload  $data  Validated submission payload
     * @param  FormSubmissionContext  $context  Request-derived submission context
     * @return FormSubmissionResult Submission result payload
     *
     * @throws Throwable
     */
    private function executeCustomSubmission(Form $form, SubmitFormPayload $data, FormSubmissionContext $context): FormSubmissionResult
    {
        $handler = $this->customRegistry->resolve($form);
        if ($handler === null) {
            throw new FormSubmissionRejectionException(
                message: (string) trans('forms::forms/messages.api.error'),
                statusCode: 400,
            );
        }

        $guard = $this->guardCustomSubmission->execute($form, $data, $context);
        $request = $context->httpRequest();
        $claim = $this->customReceipts->claim(
            $form,
            $guard->handlerPayload,
            $guard->sessionId,
            $context->idempotencyKey,
        );

        if ($claim?->isReplay === true) {
            return new FormSubmissionResult(
                form: $form,
                entryId: $claim->receipt->result_id ?? '',
                submittedAt: $claim->receipt->created_at,
            );
        }

        try {
            $result = $handler->handle($form, $guard->handlerPayload, $request);
        } catch (Throwable $throwable) {
            $this->customReceipts->fail($claim);
            $this->attemptBestEffortBookkeeping(function () use ($form, $guard, $throwable): void {
                $this->recordFormAnalytic->execute(
                    form: $form,
                    eventType: FormAnalyticEventType::ERROR,
                    origin: $guard->submittedFrom,
                    ipAddress: $guard->ipAddress,
                    userAgent: $guard->userAgent,
                    sessionId: $guard->sessionId,
                    metadata: ['exception' => get_class($throwable)],
                );
            });

            throw $throwable;
        }

        $entryId = (string) ($result['entry_id'] ?? '');
        $this->customReceipts->complete($claim, $entryId);
        $hasBookkeepingWarning = $this->finalizeCustomSubmission($form, $guard);

        return new FormSubmissionResult(
            form: $form,
            entryId: $entryId,
            submittedAt: now(),
            hasBookkeepingWarning: $hasBookkeepingWarning,
        );
    }

    /**
     * Execute a standard entry-based submission.
     *
     * @param  Form  $form  Target form
     * @param  SubmitFormPayload  $data  Validated submission payload
     * @param  FormSubmissionContext  $context  Request-derived submission context
     * @return FormSubmissionResult Submission result payload
     *
     * @throws Throwable
     */
    private function executeEntrySubmission(Form $form, SubmitFormPayload $data, FormSubmissionContext $context): FormSubmissionResult
    {
        $request = $context->httpRequest();
        $entryData = $this->prepareData->execute($form, $data, $context);
        $entry = $this->createEntry->execute(
            $entryData,
            $context->resolvedIpAddress(),
            $context->userAgent,
            $context->sessionId,
            $context->actor,
            $context->idempotencyKey,
            $this->tokenService->issuedAt($context->publicToken, $form),
        );
        if (! $entry->isIdempotentReplay()) {
            $this->entryCallbacks->dispatch($form, $entry, $request);
        }

        return new FormSubmissionResult(
            form: $form,
            entryId: (string) $entry->id,
            submittedAt: $entry->created_at ?? now(),
        );
    }

    /**
     * Persist Forms-owned submission bookkeeping without overriding downstream success.
     *
     * Once a custom handler has successfully completed its downstream work, failures in
     * Forms counters should be reported for follow-up but must not
     * surface back to the submitter as if the primary submission had failed.
     *
     * @param  Form  $form  Submitted form
     */
    private function finalizeCustomSubmission(Form $form, CustomFormGuardResult $guard): bool
    {
        return ! $this->attemptBestEffortBookkeeping(function () use ($form, $guard): void {
            $this->recordFormSubmission->execute(
                $form,
                $guard->submittedFrom,
                $guard->ipAddress,
                $guard->userAgent,
                $guard->sessionId,
            );
        });
    }

    /**
     * Report bookkeeping failures without breaking a successful custom submission result.
     *
     * @param  Closure():void  $callback
     */
    private function attemptBestEffortBookkeeping(Closure $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }
}
