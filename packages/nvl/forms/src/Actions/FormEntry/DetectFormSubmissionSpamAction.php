<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Contracts\FormSpamDetector;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormSpamDetectionService;
use Spatie\LaravelData\Optional;

/**
 * Orchestrates spam signal evaluation for a form submission.
 *
 * Delegates scoring to the configured detector and retains diagnostic flags
 * when the built-in detector is active.
 * Returns a structured result with spam/flag decisions and detected signals.
 *
 * @see FormSpamDetectionService
 */
final class DetectFormSubmissionSpamAction
{
    /**
     * @param  FormSpamDetector  $spamDetection  Configured spam scoring implementation
     */
    public function __construct(
        private readonly FormSpamDetector $spamDetection,
    ) {}

    /**
     * Evaluate a submission payload for spam signals.
     *
     * Extracts text fields (body, subject), email, and submission data from the DTO,
     * delegates scoring to the spam detection service, and returns a structured result
     * with is_spam/is_flagged decisions based on configured thresholds.
     *
     * @param  Form  $form  Resolved form model
     * @param  FormEntryPayload  $data  Submission data DTO
     * @param  string  $ipAddress  Request IP address for reputation scoring
     * @param  string|null  $userAgent  Request user agent for bot detection
     * @return array{is_spam: bool, is_flagged: bool, score: int, flags: array<string, mixed>}
     */
    public function execute(
        Form $form,
        FormEntryPayload $data,
        string $ipAddress,
        ?string $userAgent,
        ?float $trustedFormLoadTime = null,
    ): array {
        $payload = $this->normalizedPayload($data);
        $analysis = $this->spamDetection instanceof FormSpamDetectionService
            ? $this->spamDetection->analyzeSubmission(
                form: $form,
                data: $payload,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                trustedFormLoadTime: $trustedFormLoadTime,
            )
            : [
                'score' => $this->spamDetection->calculateSpamScore(
                    $form,
                    $payload,
                    $ipAddress,
                    $userAgent,
                    null,
                    $trustedFormLoadTime,
                ),
                'flags' => [],
            ];

        $finalScore = (float) $analysis['score'];

        return [
            'is_spam' => $this->spamDetection->shouldBlockSubmission($finalScore),
            'is_flagged' => $this->spamDetection->shouldFlagSubmission($finalScore),
            'score' => (int) round($finalScore),
            'flags' => $analysis['flags'],
        ];
    }

    /**
     * Build the submission payload used by the spam detection service.
     *
     * @param  FormEntryPayload  $data  Submission data DTO
     * @return array<string, mixed>
     */
    private function normalizedPayload(FormEntryPayload $data): array
    {
        $payload = ($data->submissionData instanceof Optional || $data->submissionData === null)
            ? []
            : $data->submissionData;

        foreach (['subject', 'body', 'email'] as $property) {
            $value = $data->{$property} ?? null;
            if (! ($value instanceof Optional) && $value !== null && $value !== '') {
                $payload[$property] = $value;
            }
        }

        return $payload;
    }
}
