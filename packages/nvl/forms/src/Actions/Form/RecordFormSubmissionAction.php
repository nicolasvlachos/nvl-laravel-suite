<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Support\Facades\DB;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Throwable;

/**
 * Orchestrates atomic submission counters and analytics recording.
 *
 * Delegation to RecordFormAnalyticAction is deliberate domain orchestration so
 * both write paths share one transaction and one canonical analytics contract.
 */
final class RecordFormSubmissionAction
{
    /**
     * @param  RecordFormAnalyticAction  $recordFormAnalytic  Analytics recorder
     */
    public function __construct(
        private readonly RecordFormAnalyticAction $recordFormAnalytic,
    ) {}

    /**
     * Persist counters and analytics for a form submission.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  string|null  $origin  Origin header for the submission
     * @param  string|null  $ipAddress  Originating IP address
     * @param  string|null  $userAgent  Request user agent
     * @param  string|null  $sessionId  Session identifier when available
     * @return Form Updated form instance
     *
     * @throws Throwable
     */
    public function execute(
        Form|string $form,
        ?string $origin,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $sessionId
    ): Form {
        return DB::transaction(function () use ($form, $origin, $ipAddress, $userAgent, $sessionId) {
            $formModel = $form instanceof Form
                ? $form
                : Form::findOrFail($form);

            // Format with offset — increment() bypasses cast set() for extras,
            // so the query builder would strip the timezone from raw Carbon.
            $nowFormatted = now()->format('Y-m-d H:i:sP');
            $extra = ['last_used_at' => $nowFormatted];
            if ($formModel->first_used_at === null) {
                $extra['first_used_at'] = $nowFormatted;
            }

            // Single UPDATE: increment + extra columns in one query
            $formModel->increment('submissions_count', 1, $extra);

            $this->recordFormAnalytic->execute(
                form: $formModel,
                eventType: FormAnalyticEventType::SUBMISSION,
                origin: $origin,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                sessionId: $sessionId,
            );

            return $formModel;
        });
    }
}
