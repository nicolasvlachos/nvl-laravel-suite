<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Support\FormsConfiguration;

/**
 * Read-only service for rate-limit statistics and maintenance operations.
 */
final class FormRateLimitStatisticsService
{
    /**
     * Clean up expired rate limit records.
     *
     * @return int Number of deleted records
     */
    public function cleanupExpiredRecords(): int
    {
        $cutoff = now()->subDays($this->cleanupAfterDays());

        /** @var int $deleted */
        $deleted = FormRateLimit::where('window_start', '<', $cutoff)
            ->where('is_blocked', false)
            ->delete();

        return $deleted;
    }

    /**
     * Get statistics for rate limiting across all forms.
     *
     * @return array{total_blocked_ips: int, total_violations: int, active_rate_limit_windows: int, cleanup_needed: int}
     */
    public function getGlobalStatistics(): array
    {
        $totalBlocked = FormRateLimit::where('is_blocked', true)->count();
        $totalViolationsValue = FormRateLimit::sum('violation_count');
        $totalViolations = (int) $totalViolationsValue;
        $activeWindows = FormRateLimit::where('window_start', '>', now()->subHour())->count();

        return [
            'total_blocked_ips' => $totalBlocked,
            'total_violations' => $totalViolations,
            'active_rate_limit_windows' => $activeWindows,
            'cleanup_needed' => FormRateLimit::where('window_start', '<', now()->subDays($this->cleanupAfterDays()))->count(),
        ];
    }

    /**
     * Get rate limit statistics for a specific form.
     *
     * @param  Form  $form  The form to get statistics for
     * @param  int  $days  Number of days to look back
     * @return array{total_violations: int, blocked_ips: int, most_violations: int, top_violating_ips: array<string, int>}
     */
    public function getFormStatistics(Form $form, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $baseQuery = $form->rateLimits()
            ->whereDate('created_at', '>=', $startDate);

        $totalViolations = (int) (clone $baseQuery)->sum('violation_count');
        $blockedIps = (clone $baseQuery)->where('is_blocked', true)->count();
        $maximum = (clone $baseQuery)->max('violation_count');
        $mostViolations = is_numeric($maximum) ? (int) $maximum : 0;

        /** @var array<string, int> $top */
        $top = (clone $baseQuery)
            ->where('violation_count', '>', 0)
            ->orderByDesc('violation_count')
            ->limit(10)
            ->pluck('violation_count', 'ip_address')
            ->map(fn ($v) => is_numeric($v) ? (int) $v : 0)
            ->toArray();

        return [
            'total_violations' => $totalViolations,
            'blocked_ips' => $blockedIps,
            'most_violations' => $mostViolations,
            'top_violating_ips' => $top,
        ];
    }

    /**
     * Resolve the configured cleanup retention in days.
     */
    private function cleanupAfterDays(): int
    {
        return FormsConfiguration::positiveInteger(
            'forms.security.ip_blocking.cleanup_after_days',
            7,
        );
    }
}
