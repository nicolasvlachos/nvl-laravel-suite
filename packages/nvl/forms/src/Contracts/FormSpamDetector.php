<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;

/**
 * Contract for evaluating spam signals in form submissions.
 *
 * Provides honeypot validation, multi-factor spam scoring, and
 * threshold-based blocking/flagging decisions.
 */
interface FormSpamDetector
{
    /**
     * Check if honeypot was triggered in submission data.
     *
     * @param  Form  $form  The form to check
     * @param  array<string, mixed>  $data  Submission data containing field values
     * @return bool True when a honeypot field contains a value
     */
    public function checkHoneypot(Form $form, array $data): bool;

    /**
     * Calculate a composite spam score based on configurable heuristic factors.
     *
     * Evaluates honeypot signals, text patterns, email domains, user agent
     * analysis, link density, and IP reputation from rate-limit history. Timing
     * is only used when a trusted server-issued load timestamp is provided.
     *
     * @param  Form  $form  The form being submitted
     * @param  array<string, mixed>  $data  Submission data to evaluate
     * @param  string  $ipAddress  Submitter IP address for reputation lookup
     * @param  string|null  $userAgent  Request user agent for bot detection
     * @param  FormRateLimit|null  $rateLimit  Pre-loaded rate limit record to avoid redundant queries
     * @param  float|null  $formLoadTime  Timestamp when the form was loaded (microtime)
     * @return float Spam score in the range 0-100
     */
    public function calculateSpamScore(
        Form $form,
        array $data,
        string $ipAddress,
        ?string $userAgent = null,
        ?FormRateLimit $rateLimit = null,
        ?float $formLoadTime = null,
    ): float;

    /**
     * Determine if a submission should be blocked based on its spam score.
     *
     * @param  float  $spamScore  Calculated spam score
     * @return bool True when spam score meets or exceeds the configured block threshold
     */
    public function shouldBlockSubmission(float $spamScore): bool;

    /**
     * Determine if a submission should be flagged for review without blocking.
     *
     * @param  float  $spamScore  Calculated spam score
     * @return bool True when score falls in the flag-but-not-block range
     */
    public function shouldFlagSubmission(float $spamScore): bool;
}
