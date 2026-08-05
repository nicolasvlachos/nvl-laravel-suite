<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Operation status tracking for CSV import/export processes.
 *
 * Provides comprehensive state management for long-running CSV operations:
 * - Track operation lifecycle from start to completion
 * - Support pause/resume for large operations
 * - Enable retry logic for failed operations
 * - Facilitate progress monitoring and reporting
 */
enum CSVOperationStatusEnum: string
{
    // Operation lifecycle states
    case PENDING = 'pending';           // Queued, not started
    case PREPARING = 'preparing';       // Initializing, validating
    case RUNNING = 'running';           // Actively processing
    case PAUSED = 'paused';            // Temporarily suspended
    case RESUMING = 'resuming';        // Restarting after pause
    case COMPLETED = 'completed';       // Successfully finished
    case FAILED = 'failed';            // Terminated with errors
    case CANCELLED = 'cancelled';       // User-terminated
    case RETRYING = 'retrying';        // Attempting retry after failure

    /**
     * Check if operation is currently active.
     *
     * Active operations are consuming resources and processing data.
     *
     * @return bool True if operation is actively running
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::PREPARING, self::RUNNING, self::RESUMING, self::RETRYING => true,
            default => false,
        };
    }

    /**
     * Check if operation has reached a terminal state.
     *
     * Terminal states are final and cannot transition further.
     *
     * @return bool True if operation is complete
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Check if operation can be paused.
     *
     * Only running operations support pausing.
     *
     * @return bool True if pause is allowed
     */
    public function canPause(): bool
    {
        return match ($this) {
            self::RUNNING, self::RETRYING => true,
            default => false,
        };
    }

    /**
     * Check if operation can be resumed.
     *
     * Only paused operations can be resumed.
     *
     * @return bool True if resume is allowed
     */
    public function canResume(): bool
    {
        return $this === self::PAUSED;
    }

    /**
     * Check if operation can be cancelled.
     *
     * Non-terminal operations can typically be cancelled.
     *
     * @return bool True if cancellation is allowed
     */
    public function canCancel(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Check if operation can be retried.
     *
     * Failed operations may be retried depending on failure type.
     *
     * @return bool True if retry is allowed
     */
    public function canRetry(): bool
    {
        return match ($this) {
            self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Check if operation represents success.
     *
     * Only COMPLETED status indicates successful operation.
     *
     * @return bool True if operation succeeded
     */
    public function isSuccess(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Check if operation represents failure.
     *
     * FAILED and CANCELLED are considered failure states.
     *
     * @return bool True if operation failed
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Get valid transition states from current status.
     *
     * Returns array of statuses this status can transition to
     * based on state machine rules.
     *
     * @return array<self> Valid next states
     *
     * // [PREPARING, CANCELLED]
     */
    public function getValidTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::PREPARING, self::CANCELLED],
            self::PREPARING => [self::RUNNING, self::FAILED, self::CANCELLED],
            self::RUNNING => [self::PAUSED, self::COMPLETED, self::FAILED, self::CANCELLED],
            self::PAUSED => [self::RESUMING, self::CANCELLED],
            self::RESUMING => [self::RUNNING, self::FAILED, self::CANCELLED],
            self::COMPLETED => [], // Terminal state
            self::FAILED => [self::RETRYING],
            self::CANCELLED => [self::RETRYING],
            self::RETRYING => [self::RUNNING, self::FAILED, self::CANCELLED],
        };
    }

    /**
     * Check if transition to another status is valid.
     *
     * Validates state machine transitions.
     *
     * @param  self  $newStatus  Target status
     * @return bool True if transition is allowed
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->getValidTransitions(), true);
    }

    /**
     * Get progress percentage range for this status.
     *
     * Returns min and max progress percentages typically
     * associated with this status.
     *
     * @return array{min: int, max: int} Progress range
     */
    public function getProgressRange(): array
    {
        return match ($this) {
            self::PENDING => ['min' => 0, 'max' => 0],
            self::PREPARING => ['min' => 0, 'max' => 5],
            self::RUNNING => ['min' => 1, 'max' => 99],
            self::PAUSED => ['min' => 1, 'max' => 99],
            self::RESUMING => ['min' => 1, 'max' => 99],
            self::COMPLETED => ['min' => 100, 'max' => 100],
            self::FAILED => ['min' => 0, 'max' => 99],
            self::CANCELLED => ['min' => 0, 'max' => 99],
            self::RETRYING => ['min' => 0, 'max' => 99],
        };
    }

    /**
     * Get status color for UI display.
     *
     * Returns color suitable for status badges and indicators.
     *
     * @return string Color identifier
     */
    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'gray',       // Waiting
            self::PREPARING => 'indigo',   // Starting up
            self::RUNNING => 'blue',       // Active
            self::PAUSED => 'yellow',      // Suspended
            self::RESUMING => 'cyan',      // Restarting
            self::COMPLETED => 'green',    // Success
            self::FAILED => 'red',         // Error
            self::CANCELLED => 'orange',   // User action
            self::RETRYING => 'purple',    // Retry attempt
        };
    }

    /**
     * Get status icon for visual representation.
     *
     * Returns emoji or icon for status visualization.
     *
     * @return string Unicode emoji
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',     // Hourglass
            self::PREPARING => '🔧',   // Wrench
            self::RUNNING => '▶️',     // Play button
            self::PAUSED => '⏸️',      // Pause button
            self::RESUMING => '🔄',    // Refresh
            self::COMPLETED => '✅',    // Check mark
            self::FAILED => '❌',       // X mark
            self::CANCELLED => '🛑',    // Stop sign
            self::RETRYING => '🔁',     // Repeat
        };
    }

    /**
     * Get user-friendly label for this status.
     *
     * Provides human-readable status labels for UI display.
     *
     * @return string Display label
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Queued',
            self::PREPARING => 'Preparing',
            self::RUNNING => 'Processing',
            self::PAUSED => 'Paused',
            self::RESUMING => 'Resuming',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::RETRYING => 'Retrying',
        };
    }

    /**
     * Get detailed description of this status.
     *
     * Explains what's happening during this status.
     *
     * @return string Status description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::PENDING => 'Operation is queued and waiting to start',
            self::PREPARING => 'Validating file and preparing for processing',
            self::RUNNING => 'Actively processing CSV data',
            self::PAUSED => 'Processing temporarily suspended',
            self::RESUMING => 'Restarting processing after pause',
            self::COMPLETED => 'Operation completed successfully',
            self::FAILED => 'Operation failed with errors',
            self::CANCELLED => 'Operation cancelled by user',
            self::RETRYING => 'Attempting to retry failed operation',
        };
    }

    /**
     * Get priority level for queue processing.
     *
     * Higher priority operations are processed first.
     *
     * @return int Priority level (1-10, higher is more urgent)
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::RETRYING => 8,      // High priority for retries
            self::RESUMING => 7,      // Resume quickly
            self::PREPARING => 6,     // Start new operations
            self::RUNNING => 5,       // Normal running priority
            self::PENDING => 4,       // Waiting in queue
            self::PAUSED => 3,        // Suspended
            self::COMPLETED => 1,     // Lowest (done)
            self::FAILED => 1,        // Lowest (done)
            self::CANCELLED => 1,     // Lowest (done)
        };
    }

    /**
     * Get operations in progress.
     *
     * Returns statuses that indicate work is being done.
     *
     * @return array<self> Active statuses
     */
    public static function inProgress(): array
    {
        return [
            self::PREPARING,
            self::RUNNING,
            self::RESUMING,
            self::RETRYING,
        ];
    }

    /**
     * Get terminal statuses.
     *
     * Returns statuses that represent final states.
     *
     * @return array<self> Terminal statuses
     */
    public static function terminal(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}
