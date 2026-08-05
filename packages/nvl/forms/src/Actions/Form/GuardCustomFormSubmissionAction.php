<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Support\Facades\DB;
use Nvl\Forms\Actions\FormEntry\CheckFormRateLimitAction;
use Nvl\Forms\Actions\FormEntry\DetectFormSubmissionSpamAction;
use Nvl\Forms\Actions\FormEntry\ValidateFormHostAccessAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormSpamDetectionService;
use Nvl\Forms\Services\FormSpamRejectionRecorder;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Support\CustomFormGuardResult;
use Nvl\Forms\Support\FormSubmissionContext;
use Spatie\LaravelData\Optional;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Enforces anti-abuse checks for custom form submissions before handler execution.
 *
 * Orchestrates host validation, rate limiting, honeypot detection, and spam scoring
 * for CUSTOM resolvement forms. Returns a normalized guard result or throws a
 * package-owned rejection exception for controller-layer mapping.
 *
 * @see PrepareFormSubmissionDataAction
 * @see ValidateFormHostAccessAction
 * @see CheckFormRateLimitAction
 * @see DetectFormSubmissionSpamAction
 */
final class GuardCustomFormSubmissionAction
{
    /**
     * @param  PrepareFormSubmissionDataAction  $prepareData  Submission normalizer
     * @param  ValidateFormHostAccessAction  $validateHostAccess  Host restriction validator
     * @param  CheckFormRateLimitAction  $checkRateLimit  Rate-limit guard
     * @param  DetectFormSubmissionSpamAction  $detectSpam  Spam detector
     * @param  FormSpamDetectionService  $spamDetection  Honeypot validation service
     * @param  FormSpamRejectionRecorder  $spamRejectionRecorder  Spam rejection recorder
     * @param  PublicFormTokenService  $tokenService  Public token metadata service
     */
    public function __construct(
        private readonly PrepareFormSubmissionDataAction $prepareData,
        private readonly ValidateFormHostAccessAction $validateHostAccess,
        private readonly CheckFormRateLimitAction $checkRateLimit,
        private readonly DetectFormSubmissionSpamAction $detectSpam,
        private readonly FormSpamDetectionService $spamDetection,
        private readonly FormSpamRejectionRecorder $spamRejectionRecorder,
        private readonly PublicFormTokenService $tokenService,
    ) {}

    /**
     * Execute all security checks required before invoking a custom handler.
     *
     * @param  Form  $form  Target form
     * @param  SubmitFormPayload  $submissionData  Validated submission DTO
     * @param  FormSubmissionContext  $context  Request-derived submission context
     *
     * @throws FormSubmissionRejectionException When the submission is blocked by host/rate/spam rules
     */
    public function execute(Form $form, SubmitFormPayload $submissionData, FormSubmissionContext $context): CustomFormGuardResult
    {
        $request = $context->httpRequest();
        $entryData = $this->prepareData->execute($form, $submissionData, $context);
        $ipAddress = $context->resolvedIpAddress();
        $userAgent = $context->userAgent;
        $sessionId = $context->sessionId;
        $submittedFrom = $entryData->submittedFrom instanceof Optional ? null : $entryData->submittedFrom;
        $handlerPayload = $this->buildHandlerPayload($entryData, $submissionData);

        try {
            $this->validateHostAccess->execute($form, $submittedFrom);
            $this->checkRateLimit->execute($form, $ipAddress, $submittedFrom, $userAgent, $sessionId);
        } catch (AccessDeniedHttpException $e) {
            throw new FormSubmissionRejectionException(
                message: $e->getMessage(),
                statusCode: 403,
            );
        } catch (TooManyRequestsHttpException $e) {
            throw new FormSubmissionRejectionException(
                message: $e->getMessage(),
                statusCode: 429,
            );
        }

        $submissionDataArray = $entryData->submissionData instanceof Optional ? [] : ($entryData->submissionData ?? []);
        if ($this->spamDetection->checkHoneypot($form, $submissionDataArray)) {
            $this->recordSpamRejection(
                form: $form,
                reason: 'honeypot',
                score: 100,
                flags: ['honeypot' => true],
                submittedFrom: $submittedFrom,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                sessionId: $sessionId,
            );

            throw new FormSubmissionRejectionException(
                message: (string) trans('forms::forms/shared.messages.error.bot_detected'),
                statusCode: 422,
            );
        }

        $spamDetection = $this->detectSpam->execute(
            $form,
            $entryData,
            $ipAddress,
            $userAgent,
            $this->tokenService->issuedAt($context->publicToken, $form),
        );
        if ($spamDetection['is_spam']) {
            $this->recordSpamRejection(
                form: $form,
                reason: 'spam_score',
                score: $spamDetection['score'],
                flags: $spamDetection['flags'],
                submittedFrom: $submittedFrom,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                sessionId: $sessionId,
            );

            throw new FormSubmissionRejectionException(
                message: (string) trans('forms::forms/shared.messages.error.bot_detected'),
                statusCode: 422,
            );
        }

        return new CustomFormGuardResult(
            submittedFrom: $submittedFrom,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            sessionId: $sessionId,
            handlerPayload: $handlerPayload,
        );
    }

    /**
     * Build normalized payload for custom handler execution.
     *
     * @param  FormEntryPayload  $entryData  Normalized entry payload
     * @param  SubmitFormPayload  $submissionData  Original validated DTO
     * @return array<string,mixed>
     */
    private function buildHandlerPayload(FormEntryPayload $entryData, SubmitFormPayload $submissionData): array
    {
        $payload = [];
        foreach ($submissionData->toArray() as $key => $value) {
            $payload[$key] = $value;
        }

        $subject = $entryData->subject instanceof Optional ? null : $entryData->subject;
        $firstName = $entryData->firstName instanceof Optional ? null : $entryData->firstName;
        $lastName = $entryData->lastName instanceof Optional ? null : $entryData->lastName;
        $email = $entryData->email instanceof Optional ? null : $entryData->email;
        $phone = $entryData->phone instanceof Optional ? null : $entryData->phone;
        $address = $entryData->address instanceof Optional ? null : $entryData->address;
        $body = $entryData->body instanceof Optional ? null : $entryData->body;
        $submission = $entryData->submissionData instanceof Optional ? [] : $entryData->submissionData;
        $submittedFrom = $entryData->submittedFrom instanceof Optional ? null : $entryData->submittedFrom;

        $payload['subject'] = $subject;
        $payload['firstName'] = $firstName;
        $payload['lastName'] = $lastName;
        $payload['email'] = $email;
        $payload['phone'] = $phone;
        $payload['address'] = $address;
        $payload['body'] = $body;
        $payload['submissionData'] = $submission;
        $payload['submittedFrom'] = $submittedFrom;

        return $payload;
    }

    /**
     * Record spam rejection bookkeeping inside a narrow Forms-owned transaction.
     *
     * @param  Form  $form  Submitted form
     * @param  string  $reason  Machine-readable rejection reason
     * @param  float|int  $score  Spam score
     * @param  array<string, mixed>  $flags  Spam flags
     * @param  string|null  $submittedFrom  Submitted origin
     * @param  string  $ipAddress  Submitter IP address
     * @param  string|null  $userAgent  Submitter user agent
     * @param  string|null  $sessionId  Session identifier
     */
    private function recordSpamRejection(
        Form $form,
        string $reason,
        float|int $score,
        array $flags,
        ?string $submittedFrom,
        string $ipAddress,
        ?string $userAgent,
        ?string $sessionId,
    ): void {
        DB::transaction(function () use ($form, $reason, $score, $flags, $submittedFrom, $ipAddress, $userAgent, $sessionId): void {
            $this->spamRejectionRecorder->record(
                form: $form,
                reason: $reason,
                score: $score,
                flags: $flags,
                channel: 'custom_resolvement',
                origin: $submittedFrom,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                sessionId: $sessionId,
            );
        });
    }
}
