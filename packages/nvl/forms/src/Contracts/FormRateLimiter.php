<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Carbon\CarbonInterface;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Results\FormRateLimitAttemptResult;

/**
 * Contract for form rate limiting operations.
 *
 * Handles per-IP submission tracking, blocking, unblocking, and status queries.
 * Spam detection and statistics are handled by dedicated contracts/services.
 */
interface FormRateLimiter
{
    /**
     * Check if an IP address is rate limited for a specific form.
     *
     * @param  Form  $form  The form to check
     * @param  string  $ipAddress  IP address to check
     * @return bool True when the IP is currently rate limited
     */
    public function isRateLimited(Form $form, string $ipAddress): bool;

    /**
     * Atomically consume a submission attempt and return the decision.
     *
     * This is the authoritative write path for public form submissions. It
     * increments the current window, applies any block, and returns dynamic
     * retry timing from the stored block window.
     *
     * @param  Form  $form  The form receiving the submission
     * @param  string  $ipAddress  IP address of the submitter
     * @param  string|null  $origin  Request Origin header value for analytics
     * @param  string|null  $userAgent  Request user agent for analytics
     * @param  string|null  $sessionId  Session identifier for analytics
     * @return FormRateLimitAttemptResult Atomic submission decision
     */
    public function consumeSubmissionAttempt(
        Form $form,
        string $ipAddress,
        ?string $origin = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
    ): FormRateLimitAttemptResult;

    /**
     * Record a submission attempt and block the IP if the limit is exceeded.
     *
     * @param  Form  $form  The form receiving the submission
     * @param  string  $ipAddress  IP address of the submitter
     * @param  string|null  $origin  Request Origin header value for analytics
     * @param  string|null  $userAgent  Request user agent for analytics
     * @param  string|null  $sessionId  Session identifier for analytics
     */
    public function recordSubmissionAttempt(
        Form $form,
        string $ipAddress,
        ?string $origin = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
    ): void;

    /**
     * Block an IP address for rate limit violations.
     *
     * @param  Form  $form  The form context
     * @param  FormRateLimit  $rateLimit  The rate-limit record to block
     * @param  int|null  $durationMinutes  Override block duration in minutes
     * @param  string|null  $origin  Request Origin header value for analytics
     * @param  string|null  $userAgent  Request user agent for analytics
     * @param  string|null  $sessionId  Session identifier for analytics
     */
    public function blockIpAddress(
        Form $form,
        FormRateLimit $rateLimit,
        ?int $durationMinutes = null,
        ?string $origin = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
    ): void;

    /**
     * Unblock a previously blocked IP address.
     *
     * @param  Form  $form  The form context
     * @param  string  $ipAddress  IP address to unblock
     */
    public function unblockIpAddress(Form $form, string $ipAddress): void;

    /**
     * Get the current rate limit status for an IP address.
     *
     * @param  Form  $form  The form to check
     * @param  string  $ipAddress  IP address to check
     * @return array{enabled: bool, remaining: int|null, reset_at: CarbonInterface|null, is_blocked: bool, blocked_until: CarbonInterface|null, retry_after: int, violation_count: int}
     */
    public function getRateLimitStatus(Form $form, string $ipAddress): array;

    /**
     * Clean up expired rate limit records.
     *
     * @return int Number of deleted records
     */
    public function cleanupExpiredRecords(): int;

    /**
     * Remove all rate limiting records for an IP address (whitelist).
     *
     * @param  Form  $form  The form context
     * @param  string  $ipAddress  IP address to whitelist
     */
    public function whitelistIpAddress(Form $form, string $ipAddress): void;
}
