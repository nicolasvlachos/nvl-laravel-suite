<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Contracts\CreateFormEntryContract;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Services\FormRegistrationFingerprint;
use Nvl\Forms\Services\FormSpamDetectionService;
use Nvl\Forms\Services\FormSpamRejectionRecorder;
use Spatie\LaravelData\Optional;
use Throwable;

/**
 * Orchestrates form entry creation with security checks and host-form activity capture.
 *
 * Coordinates host validation, rate-limit attempt consumption, honeypot detection,
 * spam scoring, entry persistence, and host-form activity capture.
 *
 * @see ValidateFormHostAccessAction
 * @see CheckFormRateLimitAction
 * @see DetectFormSubmissionSpamAction
 * @see PersistFormEntryAction
 */
final class CreateFormEntryAction implements CreateFormEntryContract
{
    /**
     * @param  ValidateFormHostAccessAction  $validateHostAccess  Validates origin restrictions
     * @param  CheckFormRateLimitAction  $checkRateLimit  Enforces per-IP rate limits
     * @param  DetectFormSubmissionSpamAction  $detectSpam  Evaluates spam signals
     * @param  PersistFormEntryAction  $persistEntry  Persists entry and updates counters
     * @param  FormSpamDetectionService  $spamDetection  Honeypot validation service
     * @param  FormSpamRejectionRecorder  $spamRejectionRecorder  Spam rejection recorder
     * @param  FormRegistrationFingerprint  $registrationFingerprint  Repeat-registration identity resolver
     */
    public function __construct(
        private readonly ValidateFormHostAccessAction $validateHostAccess,
        private readonly CheckFormRateLimitAction $checkRateLimit,
        private readonly DetectFormSubmissionSpamAction $detectSpam,
        private readonly PersistFormEntryAction $persistEntry,
        private readonly FormSpamDetectionService $spamDetection,
        private readonly FormSpamRejectionRecorder $spamRejectionRecorder,
        private readonly FormRegistrationFingerprint $registrationFingerprint,
    ) {}

    /**
     * Execute the form entry creation with comprehensive security checks.
     *
     * Performs host access validation, rate limit enforcement, honeypot detection,
     * spam scoring, entry persistence, and host-form activity capture. The rate-limit
     * guard consumes the submission attempt once; persistence does not record it again.
     *
     * @param  FormEntryPayload  $data  Validated form entry data
     * @param  string  $ipAddress  Request IP address
     * @param  string|null  $userAgent  Request user agent
     * @param  string|null  $sessionId  Request session identifier
     * @param  Authenticatable|null  $actor  Authenticated actor, if any
     * @return FormEntry The created form entry instance
     *
     * @throws Exception When form is not found or entry refresh fails
     * @throws Throwable When security checks fail or rate limit is exceeded
     */
    public function execute(
        FormEntryPayload $data,
        string $ipAddress,
        ?string $userAgent,
        ?string $sessionId,
        ?Authenticatable $actor = null,
        ?string $idempotencyKey = null,
        ?float $trustedFormLoadTime = null,
    ): FormEntry {
        $payloadDigest = $this->payloadDigest($data);

        try {
            /** @var array{entry?: FormEntry, form: Form, is_spam: bool, rejection?: string, duplicate?: bool} $result */
            $result = DB::transaction(function () use ($data, $ipAddress, $userAgent, $sessionId, $idempotencyKey, $trustedFormLoadTime, $payloadDigest) {
                $formId = $data->formId;
                if ($formId === '') {
                    throw new Exception((string) trans('forms::forms/shared.messages.error.not_found', [
                        'item' => (string) trans('forms::forms/general.entities.singular'),
                    ]));
                }

                $form = Form::query()
                    ->with('allowedOrigins')
                    ->whereKey($formId)
                    ->firstOrFail();

                if ($idempotencyKey !== null) {
                    $existing = FormEntry::query()
                        ->where('form_id', $form->getKey())
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing instanceof FormEntry) {
                        $this->ensureIdempotentPayloadMatches($existing, $payloadDigest);

                        return [
                            'entry' => $existing->loadMissing('form'),
                            'form' => $form,
                            'is_spam' => $existing->is_spam,
                            'duplicate' => true,
                        ];
                    }
                }

                $submittedFrom = $data->submittedFrom instanceof Optional ? null : $data->submittedFrom;
                $email = $data->email instanceof Optional ? null : $data->email;
                $registrationFingerprint = $this->registrationFingerprint->resolve(
                    $form,
                    $email,
                    $sessionId,
                );

                if ($registrationFingerprint !== null && FormEntry::query()
                    ->where('form_id', $form->getKey())
                    ->where('registration_fingerprint', $registrationFingerprint)
                    ->exists()) {
                    $this->throwDuplicateRegistration();
                }

                $this->validateHostAccess->execute($form, $submittedFrom);
                $this->checkRateLimit->execute($form, $ipAddress, $submittedFrom, $userAgent, $sessionId);

                $submissionData = $data->submissionData instanceof Optional ? [] : ($data->submissionData ?? []);
                if ($this->spamDetection->checkHoneypot($form, $submissionData)) {
                    $this->spamRejectionRecorder->record(
                        form: $form,
                        reason: 'honeypot',
                        score: 100,
                        flags: ['honeypot' => true],
                        channel: 'entries',
                        origin: $submittedFrom,
                        ipAddress: $ipAddress,
                        userAgent: $userAgent,
                        sessionId: $sessionId,
                    );

                    return [
                        'form' => $form,
                        'is_spam' => true,
                        'rejection' => 'bot_detected',
                    ];
                }

                $spamDetection = $this->detectSpam->execute(
                    $form,
                    $data,
                    $ipAddress,
                    $userAgent,
                    $trustedFormLoadTime,
                );

                $result = $this->persistEntry->execute(
                    $form,
                    $data,
                    $spamDetection,
                    $ipAddress,
                    $userAgent,
                    $sessionId,
                    $idempotencyKey,
                    $payloadDigest,
                    $registrationFingerprint,
                );
                $entry = $result['entry'];
                $form = $result['form'];

                $fresh = $entry->fresh(['form:id,handle']);
                if ($fresh === null) {
                    throw new Exception((string) trans('forms::forms/shared.messages.error.refresh_failed', [
                        'item' => (string) trans('forms::entries/general.entities.singular'),
                    ]));
                }

                return [
                    'entry' => $fresh,
                    'form' => $form,
                    'is_spam' => (bool) $spamDetection['is_spam'],
                ];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null) {
                $existing = FormEntry::query()
                    ->with('form')
                    ->where('form_id', $data->formId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing instanceof FormEntry) {
                    $this->ensureIdempotentPayloadMatches($existing, $payloadDigest);

                    return $existing->markAsIdempotentReplay();
                }
            }

            $form = Form::query()
                ->whereKey($data->formId)
                ->firstOrFail();
            $email = $data->email instanceof Optional ? null : $data->email;
            $registrationFingerprint = $this->registrationFingerprint->resolve(
                $form,
                $email,
                $sessionId,
            );

            if ($registrationFingerprint !== null && FormEntry::query()
                ->where('form_id', $form->getKey())
                ->where('registration_fingerprint', $registrationFingerprint)
                ->exists()) {
                $this->throwDuplicateRegistration();
            }

            throw $exception;
        }

        if (($result['rejection'] ?? null) === 'bot_detected') {
            throw new Exception((string) trans('forms::forms/shared.messages.error.bot_detected'));
        }

        $entry = $result['entry'] ?? null;
        if (! $entry instanceof FormEntry) {
            throw new Exception((string) trans('forms::forms/shared.messages.error.refresh_failed', [
                'item' => (string) trans('forms::entries/general.entities.singular'),
            ]));
        }

        if (($result['duplicate'] ?? false) === true) {
            return $entry->markAsIdempotentReplay();
        }

        event(FormEntryChangedEvent::for(
            form: $result['form'],
            entry: $entry,
            operation: 'created',
            actor: $actor,
            context: ['is_spam' => $result['is_spam']],
        ));

        return $entry;
    }

    private function payloadDigest(FormEntryPayload $data): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                $data->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function ensureIdempotentPayloadMatches(FormEntry $entry, string $payloadDigest): void
    {
        if ($entry->payload_digest === $payloadDigest) {
            return;
        }

        throw new FormSubmissionRejectionException(
            message: (string) trans('forms::forms/messages.error.idempotency_conflict'),
            statusCode: 409,
        );
    }

    /**
     * Reject a second registration without disclosing the identity source.
     */
    private function throwDuplicateRegistration(): never
    {
        throw new FormSubmissionRejectionException(
            message: (string) trans('forms::forms/messages.error.registration_already_exists'),
            statusCode: 409,
        );
    }
}
