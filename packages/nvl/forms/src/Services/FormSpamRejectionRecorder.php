<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Actions\Form\RecordFormAnalyticAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;

/**
 * Records Forms-owned bookkeeping for spam-classified or blocked submissions.
 */
final readonly class FormSpamRejectionRecorder
{
    public function __construct(
        private RecordFormAnalyticAction $recordFormAnalytic,
    ) {}

    /**
     * Increment spam counters and record the corresponding analytic event.
     *
     * @param  Form  $form  Submitted form
     * @param  string  $reason  Machine-readable rejection reason
     * @param  float|int  $score  Spam score at rejection time
     * @param  array<string, mixed>  $flags  Detected spam flags
     * @param  string  $channel  Submission channel, for example entries or custom_resolvement
     * @param  string|null  $origin  Submitted origin
     * @param  string  $ipAddress  Submitter IP address
     * @param  string|null  $userAgent  Submitter user agent
     * @param  string|null  $sessionId  Session identifier
     * @return Form Refreshed form after counter update
     */
    public function record(
        Form $form,
        string $reason,
        float|int $score,
        array $flags,
        string $channel,
        ?string $origin,
        string $ipAddress,
        ?string $userAgent,
        ?string $sessionId,
    ): Form {
        $form->increment('spam_count');

        $this->recordFormAnalytic->execute(
            form: $form,
            eventType: FormAnalyticEventType::SPAM_BLOCKED,
            origin: $origin,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            sessionId: $sessionId,
            metadata: [
                'reason' => $reason,
                'score' => $score,
                'flags' => $flags,
                'channel' => $channel,
            ],
        );

        return $form->refresh();
    }
}
