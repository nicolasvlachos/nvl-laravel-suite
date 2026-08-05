<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Illuminate\Support\Facades\DB;
use Nvl\Forms\Actions\Form\RecordFormSubmissionAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Services\FormSpamRejectionRecorder;
use Spatie\LaravelData\Optional;
use Throwable;

/**
 * Orchestrates entry persistence with submission analytics and counters.
 *
 * Delegation to RecordFormSubmissionAction is deliberate domain orchestration
 * so a legitimate entry and its bookkeeping commit atomically.
 */
final class PersistFormEntryAction
{
    /**
     * Create the action with analytics recording dependency.
     *
     * @param  RecordFormSubmissionAction  $recordFormSubmission  Submission analytics action
     * @param  FormSpamRejectionRecorder  $spamRejectionRecorder  Spam rejection recorder
     */
    public function __construct(
        private readonly RecordFormSubmissionAction $recordFormSubmission,
        private readonly FormSpamRejectionRecorder $spamRejectionRecorder,
    ) {}

    /**
     * Create the entry and update counters/analytics accordingly.
     *
     * @param  Form|string  $form  Form model or identifier
     * @param  FormEntryPayload  $data  Prepared entry data
     * @param  array{is_spam:bool,is_flagged?:bool,score:int,flags:array<string,mixed>}  $spamDetection  Spam detection data
     * @param  string  $ipAddress  Request IP address
     * @param  string|null  $userAgent  Request user agent
     * @param  string|null  $sessionId  Request session identifier
     * @return array{entry: FormEntry, form: Form} Persisted entry and refreshed form
     *
     * @throws Throwable When the transaction fails
     */
    public function execute(
        Form|string $form,
        FormEntryPayload $data,
        array $spamDetection,
        string $ipAddress,
        ?string $userAgent,
        ?string $sessionId,
        ?string $idempotencyKey = null,
        ?string $payloadDigest = null,
        ?string $registrationFingerprint = null,
    ): array {
        return DB::transaction(function () use ($form, $data, $spamDetection, $ipAddress, $userAgent, $sessionId, $idempotencyKey, $payloadDigest, $registrationFingerprint) {
            $submittedFrom = $data->submittedFrom instanceof Optional ? null : $data->submittedFrom;
            $form = $form instanceof Form ? $form : Form::findOrFail($form);

            $entryPayload = $data->except('id', 'createdAt')->toModelFiltered();
            $entryPayload['ip_address'] = $ipAddress;
            $entryPayload['user_agent'] = $userAgent;
            $entryPayload['session_id'] = $sessionId;
            $entryPayload['is_spam'] = $spamDetection['is_spam'];
            $entryPayload['spam_score'] = $spamDetection['score'];
            $entryPayload['security_flags'] = $spamDetection['flags'];
            $entryPayload['idempotency_key'] = $idempotencyKey;
            $entryPayload['payload_digest'] = $payloadDigest;
            $entryPayload['registration_fingerprint'] = $registrationFingerprint;

            $entry = new FormEntry;
            $entry->fill($entryPayload);
            $entry->save();

            // Flag suspicious-but-not-blocked submissions for admin review
            $isFlagged = (bool) ($spamDetection['is_flagged'] ?? false);
            if ($isFlagged && ! $spamDetection['is_spam']) {
                $entry->setSecurityFlag('spam_flagged', true);
                $entry->setSecurityFlag('spam_flagged_at', now()->toISOString());
                $entry->setSecurityFlag('spam_flagged_score', $spamDetection['score']);
                $entry->save();
            }

            if ($spamDetection['is_spam']) {
                $form = $this->spamRejectionRecorder->record(
                    form: $form,
                    reason: 'spam_score',
                    score: $spamDetection['score'],
                    flags: $spamDetection['flags'],
                    channel: 'entries',
                    origin: $submittedFrom,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                    sessionId: $sessionId
                );
            } else {
                // Legitimate submission: update counters and analytics
                $form = $this->recordFormSubmission->execute(
                    $form,
                    $submittedFrom,
                    $ipAddress,
                    $userAgent,
                    $sessionId
                );
            }

            // Ensure the entry has the minimal parent loaded for further logging
            $entry->loadMissing('form:id,handle');

            return [
                'entry' => $entry,
                'form' => $form,
            ];
        });
    }
}
