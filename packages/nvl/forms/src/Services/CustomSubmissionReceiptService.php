<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Enums\FormSubmissionReceiptState;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormSubmissionReceipt;
use Nvl\Forms\Support\CustomSubmissionClaim;
use Throwable;

/**
 * Owns durable claims around side-effecting custom form handlers.
 */
final class CustomSubmissionReceiptService
{
    public function __construct(
        private readonly FormRegistrationFingerprint $registrationFingerprint,
    ) {}

    /**
     * Atomically claim a custom submission or return its completed replay.
     *
     * @param  array<string, mixed>  $payload  Normalized handler payload
     */
    public function claim(
        Form $form,
        array $payload,
        ?string $sessionId,
        ?string $idempotencyKey,
    ): ?CustomSubmissionClaim {
        $email = $payload['email'] ?? null;
        $fingerprint = $this->registrationFingerprint->resolve(
            $form,
            is_string($email) ? $email : null,
            $sessionId,
        );

        if ($idempotencyKey === null && $fingerprint === null) {
            return null;
        }

        $payloadDigest = $this->payloadDigest($payload);

        try {
            return DB::transaction(function () use ($form, $idempotencyKey, $fingerprint, $payloadDigest): CustomSubmissionClaim {
                if ($idempotencyKey !== null) {
                    $existing = FormSubmissionReceipt::query()
                        ->where('form_id', $form->getKey())
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof FormSubmissionReceipt) {
                        return $this->resolveExistingIdempotencyClaim($existing, $payloadDigest);
                    }
                }

                if ($fingerprint !== null) {
                    $duplicate = FormSubmissionReceipt::query()
                        ->where('form_id', $form->getKey())
                        ->where('registration_fingerprint', $fingerprint)
                        ->lockForUpdate()
                        ->first();

                    if ($duplicate instanceof FormSubmissionReceipt) {
                        $this->throwDuplicateRegistration();
                    }
                }

                $receipt = FormSubmissionReceipt::query()->create([
                    'form_id' => $form->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'payload_digest' => $payloadDigest,
                    'registration_fingerprint' => $fingerprint,
                    'state' => FormSubmissionReceiptState::Processing,
                ]);

                return new CustomSubmissionClaim($receipt, false);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->resolveConcurrentClaim(
                $form,
                $idempotencyKey,
                $fingerprint,
                $payloadDigest,
            );
        }
    }

    /**
     * Mark a claimed custom submission as successfully handled.
     */
    public function complete(?CustomSubmissionClaim $claim, string $resultId): void
    {
        if ($claim === null || $claim->isReplay) {
            return;
        }

        $claim->receipt->forceFill([
            'state' => FormSubmissionReceiptState::Completed,
            'result_id' => $resultId,
        ])->save();
    }

    /**
     * Preserve a failed claim so an automatic retry cannot duplicate unknown side effects.
     */
    public function fail(?CustomSubmissionClaim $claim): void
    {
        if ($claim === null || $claim->isReplay) {
            return;
        }

        try {
            $claim->receipt->forceFill([
                'state' => FormSubmissionReceiptState::Failed,
            ])->save();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * Resolve a concurrent insert against one of the receipt uniqueness constraints.
     */
    private function resolveConcurrentClaim(
        Form $form,
        ?string $idempotencyKey,
        ?string $fingerprint,
        string $payloadDigest,
    ): CustomSubmissionClaim {
        if ($idempotencyKey !== null) {
            $existing = FormSubmissionReceipt::query()
                ->where('form_id', $form->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof FormSubmissionReceipt) {
                return $this->resolveExistingIdempotencyClaim($existing, $payloadDigest);
            }
        }

        if ($fingerprint !== null && FormSubmissionReceipt::query()
            ->where('form_id', $form->getKey())
            ->where('registration_fingerprint', $fingerprint)
            ->exists()) {
            $this->throwDuplicateRegistration();
        }

        throw new FormSubmissionRejectionException(
            message: (string) trans('forms::forms/messages.error.submission_conflict'),
            statusCode: 409,
        );
    }

    /**
     * Validate and map an existing idempotency receipt.
     */
    private function resolveExistingIdempotencyClaim(
        FormSubmissionReceipt $receipt,
        string $payloadDigest,
    ): CustomSubmissionClaim {
        if (! hash_equals($receipt->payload_digest, $payloadDigest)) {
            throw new FormSubmissionRejectionException(
                message: (string) trans('forms::forms/messages.error.idempotency_conflict'),
                statusCode: 409,
            );
        }

        if ($receipt->state !== FormSubmissionReceiptState::Completed) {
            throw new FormSubmissionRejectionException(
                message: (string) trans('forms::forms/messages.error.submission_conflict'),
                statusCode: 409,
            );
        }

        return new CustomSubmissionClaim($receipt, true);
    }

    /**
     * Hash a normalized handler payload without persisting its PII.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadDigest(array $payload): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * Reject a second registration without exposing how the identity was derived.
     */
    private function throwDuplicateRegistration(): never
    {
        throw new FormSubmissionRejectionException(
            message: (string) trans('forms::forms/messages.error.registration_already_exists'),
            statusCode: 409,
        );
    }
}
