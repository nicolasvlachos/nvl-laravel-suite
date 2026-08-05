<?php

declare(strict_types=1);

namespace Nvl\Forms\Results;

use Carbon\CarbonInterface;
use Nvl\Forms\Models\FormRateLimit;

/**
 * Result returned after consuming a form submission rate-limit attempt.
 *
 * @property bool $allowed Whether the current submission may continue
 * @property int $retryAfterSeconds Seconds until the block can be retried, or 0 when allowed
 * @property CarbonInterface|null $blockedUntil Timestamp for the active block, when present
 * @property int $submissionCount Current submission count in the active window
 * @property int $violationCount Current violation count for the IP/form pair
 * @property FormRateLimit|null $rateLimit Locked rate-limit row when rate limiting is enabled
 */
final readonly class FormRateLimitAttemptResult
{
    public function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
        public ?CarbonInterface $blockedUntil,
        public int $submissionCount,
        public int $violationCount,
        public ?FormRateLimit $rateLimit,
    ) {}

    /**
     * Build an allowed result from the current rate-limit row.
     *
     * @param  FormRateLimit|null  $rateLimit  Locked rate-limit row, null when disabled
     */
    public static function allowed(?FormRateLimit $rateLimit): self
    {
        return new self(
            allowed: true,
            retryAfterSeconds: 0,
            blockedUntil: $rateLimit?->blocked_until,
            submissionCount: $rateLimit instanceof FormRateLimit ? (int) $rateLimit->submission_count : 0,
            violationCount: $rateLimit instanceof FormRateLimit ? (int) $rateLimit->violation_count : 0,
            rateLimit: $rateLimit,
        );
    }

    /**
     * Build a denied result from the current rate-limit row.
     *
     * @param  FormRateLimit  $rateLimit  Locked rate-limit row
     * @param  int  $retryAfterSeconds  Seconds until the block expires
     */
    public static function denied(FormRateLimit $rateLimit, int $retryAfterSeconds): self
    {
        return new self(
            allowed: false,
            retryAfterSeconds: $retryAfterSeconds,
            blockedUntil: $rateLimit->blocked_until,
            submissionCount: (int) $rateLimit->submission_count,
            violationCount: (int) $rateLimit->violation_count,
            rateLimit: $rateLimit,
        );
    }
}
