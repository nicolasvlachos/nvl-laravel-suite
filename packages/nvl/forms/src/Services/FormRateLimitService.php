<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Forms\Actions\Form\RecordFormAnalyticAction;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Results\FormRateLimitAttemptResult;

/**
 * Handles form rate limiting: per-IP submission tracking, blocking, and unblocking.
 *
 * Uses request-scoped in-memory caching to reduce DB lookups within a single request.
 * Escalating block durations are configured via `forms.security.rate_limiting.block_duration_minutes`.
 *
 * The submission consume path owns a short transaction with a row lock. This is
 * a deliberate service-owned exception because correctness depends on serialising
 * increments for the `(form_id, ip_address)` rate-limit row.
 *
 * @property RecordFormAnalyticAction $recordFormAnalytic
 * @property FormRateLimitStatisticsService $statistics
 */
final class FormRateLimitService implements FormRateLimiter
{
    /**
     * @param  RecordFormAnalyticAction  $recordFormAnalytic  Records analytics events on rate-limit violations
     * @param  FormRateLimitStatisticsService  $statistics  Provides cleanup and aggregate queries
     */
    public function __construct(
        private readonly RecordFormAnalyticAction $recordFormAnalytic,
        private readonly FormRateLimitStatisticsService $statistics,
    ) {}

    /**
     * Check if an IP address is rate limited for a specific form.
     *
     * Returns false immediately when rate limiting is disabled on the form.
     * Does not mutate counters; use consumeSubmissionAttempt() for writes.
     *
     * @param  Form  $form  The form to check
     * @param  string  $ipAddress  IP address to check
     * @return bool True when the IP is currently blocked or has exceeded the hourly limit
     */
    public function isRateLimited(Form $form, string $ipAddress): bool
    {
        if (! $form->enable_rate_limiting) {
            return false;
        }

        $rateLimit = FormRateLimit::query()
            ->where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->first();

        if (! $rateLimit instanceof FormRateLimit) {
            return false;
        }

        if ($this->isCurrentlyBlocked($rateLimit)) {
            return true;
        }

        if ($this->isExpiredWindow($rateLimit)) {
            return false;
        }

        return (int) $rateLimit->submission_count >= $this->maxAttemptsPerHour($form);
    }

    /**
     * Atomically consume a submission attempt and decide if it may continue.
     *
     * @param  Form  $form  The form receiving the submission
     * @param  string  $ipAddress  IP address of the submitter
     * @param  string|null  $origin  Request Origin header value
     * @param  string|null  $userAgent  Request user agent
     * @param  string|null  $sessionId  Session identifier
     * @return FormRateLimitAttemptResult Atomic submission decision
     */
    public function consumeSubmissionAttempt(
        Form $form,
        string $ipAddress,
        ?string $origin = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
    ): FormRateLimitAttemptResult {
        if (! $form->enable_rate_limiting) {
            return FormRateLimitAttemptResult::allowed(null);
        }

        /** @var FormRateLimitAttemptResult $result */
        $result = DB::transaction(function () use ($form, $ipAddress, $origin, $userAgent, $sessionId): FormRateLimitAttemptResult {
            $rateLimit = $this->lockRateLimitForUpdate($form, $ipAddress);

            if ($this->isCurrentlyBlocked($rateLimit)) {
                return FormRateLimitAttemptResult::denied(
                    rateLimit: $rateLimit,
                    retryAfterSeconds: $this->retryAfterSeconds($rateLimit),
                );
            }

            if ($rateLimit->is_blocked) {
                $rateLimit->forceFill([
                    'is_blocked' => false,
                    'blocked_until' => null,
                ])->save();
            }

            if ($this->isExpiredWindow($rateLimit)) {
                $rateLimit->forceFill([
                    'submission_count' => 0,
                    'window_start' => now(),
                ])->save();
            }

            if ((int) $rateLimit->submission_count >= $this->maxAttemptsPerHour($form)) {
                $this->applyBlock($form, $rateLimit, null, $origin, $userAgent, $sessionId);

                return FormRateLimitAttemptResult::denied(
                    rateLimit: $rateLimit,
                    retryAfterSeconds: $this->retryAfterSeconds($rateLimit),
                );
            }

            $newSubmissionCount = (int) $rateLimit->submission_count + 1;
            $rateLimit->forceFill([
                'submission_count' => $newSubmissionCount,
                'last_submission_at' => now(),
            ])->save();

            if ($newSubmissionCount >= $this->maxAttemptsPerHour($form)) {
                $this->applyBlock($form, $rateLimit, null, $origin, $userAgent, $sessionId);
            }

            return FormRateLimitAttemptResult::allowed($rateLimit);
        }, 3);

        return $result;
    }

    /**
     * Record a submission attempt and auto-block the IP if the limit is exceeded.
     *
     * Increments the submission counter atomically and triggers a block with
     * escalating duration when the threshold is crossed.
     *
     * @param  Form  $form  The form receiving the submission
     * @param  string  $ipAddress  IP address of the submitter
     * @param  string|null  $origin  Request Origin header value
     * @param  string|null  $userAgent  Request user agent string
     * @param  string|null  $sessionId  Session identifier
     */
    public function recordSubmissionAttempt(
        Form $form,
        string $ipAddress,
        ?string $origin = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
    ): void {
        $this->consumeSubmissionAttempt($form, $ipAddress, $origin, $userAgent, $sessionId);
    }

    /**
     * Block an IP address for rate limit violations with escalating duration.
     *
     * Atomically increments the violation count and sets block fields in a single query.
     * Records a RATE_LIMITED analytics event for audit.
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
    ): void {
        $this->applyBlock($form, $rateLimit, $durationMinutes, $origin, $userAgent, $sessionId);
    }

    /**
     * Unblock an IP address by clearing its block status.
     *
     * @param  Form  $form  The form context
     * @param  string  $ipAddress  IP address to unblock
     */
    public function unblockIpAddress(Form $form, string $ipAddress): void
    {
        $rateLimit = FormRateLimit::where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->first();

        $rateLimit?->update([
            'is_blocked' => false,
            'blocked_until' => null,
        ]);
    }

    /**
     * Get detailed rate limit status for an IP address.
     *
     * Returns a structured array with remaining submissions, reset time,
     * block status, and violation history without creating a new row.
     *
     * @param  Form  $form  The form to check
     * @param  string  $ipAddress  IP address to check
     * @return array{enabled: bool, remaining: int|null, reset_at: CarbonInterface|null, is_blocked: bool, blocked_until: CarbonInterface|null, retry_after: int, violation_count: int}
     */
    public function getRateLimitStatus(Form $form, string $ipAddress): array
    {
        if (! $form->enable_rate_limiting) {
            return [
                'enabled' => false,
                'remaining' => null,
                'reset_at' => null,
                'is_blocked' => false,
                'blocked_until' => null,
                'retry_after' => 0,
                'violation_count' => 0,
            ];
        }

        $rateLimit = FormRateLimit::query()
            ->where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->first();

        if (! $rateLimit instanceof FormRateLimit) {
            return [
                'enabled' => true,
                'remaining' => $this->maxAttemptsPerHour($form),
                'reset_at' => null,
                'is_blocked' => false,
                'blocked_until' => null,
                'retry_after' => 0,
                'violation_count' => 0,
            ];
        }

        $submissionCount = $this->isExpiredWindow($rateLimit) ? 0 : (int) $rateLimit->submission_count;

        return [
            'enabled' => true,
            'remaining' => max(0, $this->maxAttemptsPerHour($form) - $submissionCount),
            'reset_at' => $rateLimit->window_start->copy()->addHour(),
            'is_blocked' => $this->isCurrentlyBlocked($rateLimit),
            'blocked_until' => $rateLimit->blocked_until,
            'retry_after' => $this->isCurrentlyBlocked($rateLimit) ? $this->retryAfterSeconds($rateLimit) : 0,
            'violation_count' => $rateLimit->violation_count,
        ];
    }

    /**
     * Delete expired rate limit records older than the configured retention period.
     *
     * @return int Number of deleted records
     */
    public function cleanupExpiredRecords(): int
    {
        return $this->statistics->cleanupExpiredRecords();
    }

    /**
     * Remove all rate limiting records for an IP address.
     *
     * @param  Form  $form  The form context
     * @param  string  $ipAddress  IP address to whitelist
     */
    public function whitelistIpAddress(Form $form, string $ipAddress): void
    {
        FormRateLimit::where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->delete();
    }

    /**
     * Resolve and lock the rate-limit row for a form/IP pair.
     *
     * @param  Form  $form  The target form
     * @param  string  $ipAddress  IP address to look up
     * @return FormRateLimit Locked row for the current transaction
     */
    private function lockRateLimitForUpdate(Form $form, string $ipAddress): FormRateLimit
    {
        $now = now();

        FormRateLimit::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'form_id' => $form->id,
            'ip_address' => $ipAddress,
            'submission_count' => 0,
            'window_start' => $now->format('Y-m-d H:i:sP'),
            'last_submission_at' => $now->format('Y-m-d H:i:sP'),
            'is_blocked' => false,
            'blocked_until' => null,
            'violation_count' => 0,
            'created_at' => $now->format('Y-m-d H:i:sP'),
            'updated_at' => $now->format('Y-m-d H:i:sP'),
        ]);

        $rateLimit = FormRateLimit::query()
            ->where('form_id', $form->id)
            ->where('ip_address', $ipAddress)
            ->lockForUpdate()
            ->firstOrFail();

        return $rateLimit;
    }

    /**
     * Determine whether a record is currently within an active block window.
     *
     * @param  FormRateLimit  $rateLimit  The rate-limit record
     * @return bool True when the IP is still blocked
     */
    private function isCurrentlyBlocked(FormRateLimit $rateLimit): bool
    {
        if (! $rateLimit->is_blocked) {
            return false;
        }

        if ($rateLimit->blocked_until === null) {
            return true;
        }

        return $rateLimit->blocked_until->isFuture();
    }

    /**
     * Calculate block duration based on violation count using configured escalation tiers.
     *
     * Falls back to 1440 minutes (24 hours) when no matching tier is found.
     *
     * @param  int  $violationCount  Number of previous violations
     * @return int Block duration in minutes
     */
    private function calculateBlockDuration(int $violationCount): int
    {
        /** @var array<int, int> $durations */
        $durations = config('forms.security.rate_limiting.block_duration_minutes', []);

        return $durations[$violationCount] ?? $durations[array_key_last($durations) ?? 5] ?? 1440;
    }

    /**
     * Apply a rate-limit block to the current row and record audit analytics.
     *
     * @param  Form  $form  The form context
     * @param  FormRateLimit  $rateLimit  The row being blocked
     * @param  int|null  $durationMinutes  Override block duration
     * @param  string|null  $origin  Request Origin header value for analytics
     * @param  string|null  $userAgent  Request user agent for analytics
     * @param  string|null  $sessionId  Session identifier for analytics
     */
    private function applyBlock(
        Form $form,
        FormRateLimit $rateLimit,
        ?int $durationMinutes,
        ?string $origin,
        ?string $userAgent,
        ?string $sessionId,
    ): void {
        $violationCount = max(1, (int) $rateLimit->violation_count + 1);
        $blockDuration = $durationMinutes ?? $this->calculateBlockDuration($violationCount);
        $blockedUntil = now()->addMinutes($blockDuration);

        $rateLimit->forceFill([
            'is_blocked' => true,
            'blocked_until' => $blockedUntil,
            'violation_count' => $violationCount,
        ])->save();

        $this->recordFormAnalytic->execute(
            form: $form,
            eventType: FormAnalyticEventType::RATE_LIMITED,
            origin: $origin,
            ipAddress: $rateLimit->ip_address,
            userAgent: $userAgent,
            sessionId: $sessionId,
            metadata: [
                'violation_count' => $violationCount,
                'blocked_until' => $blockedUntil->toISOString(),
                'block_duration_minutes' => $blockDuration,
            ],
        );

    }

    /**
     * Determine whether the submission window is older than one hour.
     *
     * @param  FormRateLimit  $rateLimit  Rate-limit row
     */
    private function isExpiredWindow(FormRateLimit $rateLimit): bool
    {
        return $rateLimit->window_start->isBefore(now()->subHour());
    }

    /**
     * Resolve the configured max attempts, enforcing a safe minimum.
     *
     * @param  Form  $form  Form instance
     */
    private function maxAttemptsPerHour(Form $form): int
    {
        return max(1, (int) $form->rate_limit_per_hour);
    }

    /**
     * Calculate retry timing from the stored block window.
     *
     * @param  FormRateLimit  $rateLimit  Rate-limit row
     */
    private function retryAfterSeconds(FormRateLimit $rateLimit): int
    {
        if ($rateLimit->blocked_until instanceof CarbonInterface) {
            return max(1, (int) ceil(now()->diffInSeconds($rateLimit->blocked_until, true)));
        }

        return max(60, $this->calculateBlockDuration(max(1, (int) $rateLimit->violation_count)) * 60);
    }
}
