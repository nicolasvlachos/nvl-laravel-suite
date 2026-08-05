<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Error severity classification for CSV processing operations.
 *
 * Defines hierarchical error levels for consistent error handling:
 * - Determines processing flow (continue, skip, stop)
 * - Guides notification and logging strategies
 * - Enables configurable error tolerance
 * - Supports detailed error reporting and analytics
 */
enum CSVErrorLevelEnum: string
{
    // Severity levels from lowest to highest
    case DEBUG = 'debug';           // Development information only
    case INFO = 'info';             // Informational messages
    case WARNING = 'warning';       // Potential issues, processing continues
    case ERROR = 'error';           // Recoverable errors, row skipped
    case CRITICAL = 'critical';     // Unrecoverable errors, processing stops

    /**
     * Check if this error level should stop processing.
     *
     * Critical errors halt the entire import/export operation,
     * while other levels allow processing to continue.
     *
     * @return bool True if processing should stop
     */
    public function shouldStopProcessing(): bool
    {
        return $this === self::CRITICAL;
    }

    /**
     * Check if this error level should skip the current row.
     *
     * ERROR and CRITICAL levels cause the current row to be skipped,
     * while WARNING and below allow the row to be processed.
     *
     * @return bool True if current row should be skipped
     */
    public function shouldSkipRow(): bool
    {
        return match ($this) {
            self::ERROR, self::CRITICAL => true,
            default => false,
        };
    }

    /**
     * Check if this error level should be logged.
     *
     * Determines which error levels are written to log files.
     * INFO and above are logged by default, DEBUG only in debug mode.
     *
     * @param  bool  $debugMode  Whether debug logging is enabled
     * @return bool True if error should be logged
     */
    public function shouldLog(bool $debugMode = false): bool
    {
        if ($this === self::DEBUG) {
            return $debugMode;
        }

        return true; // Log everything INFO and above
    }

    /**
     * Check if this error level should trigger notifications.
     *
     * Determines which errors should notify administrators or users.
     * Typically ERROR and CRITICAL levels trigger notifications.
     *
     * @return bool True if notifications should be sent
     */
    public function shouldNotify(): bool
    {
        return match ($this) {
            self::ERROR, self::CRITICAL => true,
            default => false,
        };
    }

    /**
     * Get numeric severity level for comparison.
     *
     * Higher numbers indicate more severe errors.
     * Useful for filtering and threshold comparisons.
     *
     * @return int Numeric level (1-5)
     */
    public function getNumericLevel(): int
    {
        return match ($this) {
            self::DEBUG => 1,
            self::INFO => 2,
            self::WARNING => 3,
            self::ERROR => 4,
            self::CRITICAL => 5,
        };
    }

    /**
     * Check if this level is more severe than another.
     *
     * Compares error severity for filtering and escalation logic.
     *
     * @param  self  $other  Error level to compare against
     * @return bool True if this level is more severe
     */
    public function isMoreSevereThan(self $other): bool
    {
        return $this->getNumericLevel() > $other->getNumericLevel();
    }

    /**
     * Check if this level meets minimum severity threshold.
     *
     * Used for configurable error handling based on tolerance levels.
     *
     * @param  self  $threshold  Minimum severity level
     * @return bool True if meets or exceeds threshold
     */
    public function meetsThreshold(self $threshold): bool
    {
        return $this->getNumericLevel() >= $threshold->getNumericLevel();
    }

    /**
     * Get the log level for Laravel's logging system.
     *
     * Maps CSV error levels to PSR-3 log levels used by Monolog.
     *
     * @return string PSR-3 log level
     */
    public function getLogLevel(): string
    {
        return match ($this) {
            self::DEBUG => 'debug',
            self::INFO => 'info',
            self::WARNING => 'warning',
            self::ERROR => 'error',
            self::CRITICAL => 'critical',
        };
    }

    /**
     * Get console output color for this error level.
     *
     * Returns ANSI color codes for colored console output
     * to improve visibility of different error levels.
     *
     * @return string Console color name
     */
    public function getConsoleColor(): string
    {
        return match ($this) {
            self::DEBUG => 'gray',      // Subdued for debug info
            self::INFO => 'blue',       // Informational
            self::WARNING => 'yellow',  // Attention needed
            self::ERROR => 'red',       // Error occurred
            self::CRITICAL => 'red',    // Critical failure (bold red)
        };
    }

    /**
     * Get icon or emoji for visual representation.
     *
     * Provides visual indicators for different error levels
     * in user interfaces and reports.
     *
     * @return string Unicode emoji or icon
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::DEBUG => '🐛',    // Bug for debug
            self::INFO => 'ℹ️',     // Information
            self::WARNING => '⚠️',   // Warning triangle
            self::ERROR => '❌',     // Error X
            self::CRITICAL => '🔥',  // Fire for critical
        };
    }

    /**
     * Get user-friendly label for this error level.
     *
     * Provides human-readable labels for UI display
     * and error reporting.
     *
     * @return string Display label
     */
    public function label(): string
    {
        return match ($this) {
            self::DEBUG => 'Debug',
            self::INFO => 'Information',
            self::WARNING => 'Warning',
            self::ERROR => 'Error',
            self::CRITICAL => 'Critical Error',
        };
    }

    /**
     * Get description of this error level's impact.
     *
     * Explains what happens when this error level occurs
     * during CSV processing operations.
     *
     * @return string Impact description
     */
    public function getImpactDescription(): string
    {
        return match ($this) {
            self::DEBUG => 'Development information only, no impact on processing',
            self::INFO => 'Informational message, processing continues normally',
            self::WARNING => 'Potential issue detected, row processed with caution',
            self::ERROR => 'Row cannot be processed and will be skipped',
            self::CRITICAL => 'Fatal error, entire operation will be terminated',
        };
    }

    /**
     * Create error level from numeric value.
     *
     * Converts numeric severity (1-5) to corresponding enum case.
     *
     * @param  int  $level  Numeric level (1-5)
     * @return self|null Error level or null if invalid
     */
    public static function fromNumeric(int $level): ?self
    {
        return match ($level) {
            1 => self::DEBUG,
            2 => self::INFO,
            3 => self::WARNING,
            4 => self::ERROR,
            5 => self::CRITICAL,
            default => null,
        };
    }

    /**
     * Get all error levels sorted by severity.
     *
     * Returns all enum cases ordered from least to most severe.
     *
     * @return array<self> Sorted error levels
     */
    public static function sortedBySeverity(): array
    {
        return [
            self::DEBUG,
            self::INFO,
            self::WARNING,
            self::ERROR,
            self::CRITICAL,
        ];
    }

    /**
     * Get error levels that stop processing.
     *
     * Returns levels that cause import/export to halt.
     *
     * @return array<self> Stopping error levels
     */
    public static function stoppingLevels(): array
    {
        return [self::CRITICAL];
    }

    /**
     * Get error levels that skip rows.
     *
     * Returns levels that cause current row to be skipped.
     *
     * @return array<self> Row-skipping error levels
     */
    public static function skippingLevels(): array
    {
        return [self::ERROR, self::CRITICAL];
    }
}
