<?php

declare(strict_types=1);

namespace Nvl\Csv\Support;

use DivisionByZeroError;
use InvalidArgumentException;
use Nvl\Csv\Exceptions\CSVMemoryException;

/**
 * Memory management for CSV operations.
 *
 * Provides memory monitoring, allocation checking, and cleanup functionality
 * for CSV processing operations to prevent out-of-memory errors.
 */
final class CSVMemoryManager
{
    /**
     * Memory limit in bytes.
     */
    private int $limit;

    /**
     * Initial memory usage when manager was created.
     */
    private int $initialUsage;

    /**
     * Peak memory usage recorded.
     */
    private int $peakUsage = 0;

    /**
     * Whether automatic cleanup is enabled.
     */
    private bool $autoCleanup = true;

    /**
     * Cleanup threshold percentage (0-100).
     */
    private int $cleanupThreshold = 80;

    /**
     * Create a new memory manager with optional custom limit.
     *
     * Initializes memory monitoring with the specified limit or system default.
     * Records initial memory usage for tracking consumption during operations.
     *
     * @param  int|null  $limit  Memory limit in bytes (null = use system memory_limit setting)
     * @return void
     */
    public function __construct(?int $limit = null)
    {
        $this->limit = $limit ?? $this->getSystemMemoryLimit();
        $this->assertValidLimit($this->limit);
        $this->initialUsage = memory_get_usage(true);
    }

    /**
     * Set the memory limit for operations.
     *
     * Updates both the internal limit and attempts to set the PHP memory_limit
     * configuration. Use -1 for unlimited memory (not recommended for production).
     *
     * @param  int  $bytes  Memory limit in bytes (-1 for unlimited)
     */
    public function setLimit(int $bytes): void
    {
        $this->assertValidLimit($bytes);
        $this->limit = $bytes;
    }

    /**
     * Get the current configured memory limit.
     *
     * Returns the memory limit that is being enforced by this manager.
     * May differ from PHP's memory_limit if explicitly overridden.
     *
     * @return int Current memory limit in bytes (-1 if unlimited)
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the current memory usage including allocated but unused memory.
     *
     * Returns actual memory usage including internal PHP structures.
     * Also updates peak usage tracking for statistics.
     *
     * @return int Current memory usage in bytes
     */
    public function getCurrentUsage(): int
    {
        $current = memory_get_usage(true);
        $this->peakUsage = max($this->peakUsage, $current);

        return $current;
    }

    /**
     * Get the memory usage recorded when this manager was created.
     *
     * Useful for calculating net memory consumption of operations
     * by comparing with current usage.
     *
     * @return int Initial memory usage in bytes at manager creation time
     */
    public function getInitialUsage(): int
    {
        return $this->initialUsage;
    }

    /**
     * Get the peak memory usage recorded during operations.
     *
     * Returns the highest memory usage seen either during manager monitoring
     * or from PHP's built-in peak tracking, whichever is higher.
     *
     * @return int Peak memory usage in bytes
     */
    public function getPeakUsage(): int
    {
        return max($this->peakUsage, memory_get_peak_usage(true));
    }

    /**
     * Get the amount of memory available before hitting the limit.
     *
     * Calculates remaining memory based on current usage and configured limit.
     * Returns PHP_INT_MAX if no limit is set (-1).
     *
     * @return int Available memory in bytes (PHP_INT_MAX if unlimited)
     */
    public function getAvailable(): int
    {
        if ($this->limit === -1) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limit - $this->getCurrentUsage());
    }

    /**
     * Get the current memory usage as a percentage of the limit.
     *
     * Calculates what percentage of the memory limit is currently being used.
     * Returns 0 if unlimited memory is configured.
     *
     * @return float Usage percentage (0.0 to 100.0)
     *
     * @throws DivisionByZeroError If the memory limit is explicitly set to 0
     */
    public function getUsagePercentage(): float
    {
        if ($this->limit === -1) {
            return 0;
        }

        return ($this->getCurrentUsage() / $this->limit) * 100;
    }

    /**
     * Check if the specified amount of memory can be allocated safely.
     *
     * Verifies that allocating the requested bytes would not exceed the memory limit.
     * Automatically attempts cleanup if allocation would fail and auto-cleanup is enabled.
     *
     * @param  int  $bytes  Number of bytes to check for allocation
     * @return bool True if allocation is possible within limits
     */
    public function canAllocate(int $bytes): bool
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('Allocation size cannot be negative.');
        }

        if ($this->limit === -1) {
            return true;
        }

        $available = $this->getAvailable();

        if ($available < $bytes) {
            // Try cleanup first
            if ($this->autoCleanup) {
                $this->cleanup();
                $available = $this->getAvailable();
            }
        }

        return $available >= $bytes;
    }

    /**
     * Assert that memory can be allocated or throw an exception.
     *
     * Checks if the requested memory allocation is possible and throws
     * a detailed exception if it would exceed the configured limits.
     *
     * @param  int  $bytes  Number of bytes required for allocation
     *
     * @throws CSVMemoryException If the allocation would exceed memory limits
     */
    public function assertCanAllocate(int $bytes): void
    {
        if (! $this->canAllocate($bytes)) {
            throw CSVMemoryException::insufficientMemory($bytes, $this->getAvailable());
        }
    }

    /**
     * Check if memory usage has reached critical levels.
     *
     * Determines if current memory usage exceeds the cleanup threshold,
     * indicating that cleanup operations should be performed.
     *
     * @return bool True if memory usage exceeds the critical threshold
     *
     * @throws DivisionByZeroError If percentage calculation encounters invalid limit
     */
    public function isCritical(): bool
    {
        return $this->getUsagePercentage() >= $this->cleanupThreshold;
    }

    /**
     * Perform aggressive memory cleanup operations.
     *
     * Forces PHP garbage collection to free unused cyclic references.
     */
    public function cleanup(): void
    {
        gc_collect_cycles();
    }

    /**
     * Enable automatic memory cleanup when usage exceeds threshold.
     *
     * Configures the manager to automatically perform cleanup operations
     * when memory usage reaches the specified percentage of the limit.
     *
     * @param  int  $threshold  Usage percentage threshold for triggering cleanup (0-100)
     */
    public function enableAutoCleanup(int $threshold = 80): void
    {
        if ($threshold < 1 || $threshold > 100) {
            throw new InvalidArgumentException('Cleanup threshold must be between 1 and 100.');
        }

        $this->autoCleanup = true;
        $this->cleanupThreshold = $threshold;
    }

    /**
     * Disable automatic memory cleanup operations.
     *
     * Turns off automatic cleanup, requiring manual cleanup calls
     * when memory management is needed.
     */
    public function disableAutoCleanup(): void
    {
        $this->autoCleanup = false;
    }

    /**
     * Parse the system memory limit from PHP configuration.
     *
     * Reads the memory_limit setting from PHP configuration and converts
     * it to bytes. Handles various formats (M, G, K suffixes) and unlimited (-1).
     *
     * @return int Memory limit in bytes, or -1 if unlimited
     */
    private function getSystemMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1' || $limit === '') {
            return -1;
        }

        return $this->parseMemoryLimit($limit);
    }

    /**
     * Convert memory limit string notation to bytes.
     *
     * Parses PHP memory limit strings with size suffixes (K, M, G)
     * and converts them to the equivalent number of bytes.
     *
     * @param  string  $limit  Memory limit string (e.g., '128M', '1G', '512K')
     * @return int Memory limit converted to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '') {
            throw new InvalidArgumentException('Memory limit cannot be empty.');
        }

        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        $multiplier = match ($last) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $value * $multiplier;
    }

    /**
     * Estimate memory requirements for processing a specific number of rows.
     *
     * Calculates the approximate memory needed to process the given number
     * of CSV rows, including overhead for data structures and processing buffers.
     *
     * @param  int  $rowCount  Number of rows to process
     * @param  int  $avgRowSize  Average size per row in bytes (default: 1024)
     * @return int Estimated total memory requirement in bytes
     */
    public function estimateMemoryForRows(int $rowCount, int $avgRowSize = 1024): int
    {
        if ($rowCount < 0) {
            throw new InvalidArgumentException('Row count cannot be negative.');
        }

        if ($avgRowSize < 1) {
            throw new InvalidArgumentException('Average row size must be at least 1 byte.');
        }

        return (int) ($rowCount * $avgRowSize * 1.2);
    }

    /**
     * Calculate the optimal chunk size for memory-efficient processing.
     *
     * Determines the ideal number of rows to process in each chunk based on
     * available memory and average row size. Ensures efficient processing
     * while staying within memory constraints.
     *
     * @param  int  $avgRowSize  Average size per row in bytes (default: 1024)
     * @return int Optimal number of rows per chunk (between 10 and 10,000)
     *
     * @throws DivisionByZeroError If average row size is zero
     */
    public function calculateOptimalChunkSize(int $avgRowSize = 1024): int
    {
        if ($avgRowSize < 1) {
            throw new InvalidArgumentException('Average row size must be at least 1 byte.');
        }

        if ($this->limit === -1) {
            return 10000;
        }

        $available = $this->getAvailable();

        // Use 50% of available memory for safety
        $usable = (int) ($available * 0.5);

        $chunkSize = (int) ($usable / $avgRowSize);

        // Ensure reasonable bounds
        return max(10, min(10000, $chunkSize));
    }

    /**
     * Monitor memory usage during the execution of an operation.
     *
     * Wraps an operation with memory monitoring, recording memory usage
     * before, during, and after execution. Logs detailed statistics
     * for performance analysis and debugging.
     *
     * @param  callable  $operation  Operation to monitor for memory usage
     * @return mixed Return value from the monitored operation
     */
    public function monitor(callable $operation): mixed
    {
        $startMemory = $this->getCurrentUsage();
        $startTime = microtime(true);

        try {
            $result = $operation();
        } finally {
            $endMemory = $this->getCurrentUsage();
            $endTime = microtime(true);

            $this->logMemoryUsage([
                'operation_memory' => $endMemory - $startMemory,
                'operation_time' => $endTime - $startTime,
                'peak_usage' => $this->getPeakUsage(),
                'final_usage' => $endMemory,
            ]);
        }

        return $result;
    }

    /**
     * Log memory usage statistics to the application log.
     *
     * Records detailed memory statistics including operation duration,
     * memory consumption, and peak usage. Only logs in debug mode
     * to avoid performance impact in production.
     *
     * @param  array<string, mixed>  $stats  Associative array of memory usage statistics
     */
    private function logMemoryUsage(array $stats): void
    {
        // This could be extended to log to file or monitoring service
        if (config('app.debug')) {
            logger()->debug('CSV Memory Usage', $stats);
        }
    }

    /**
     * Get comprehensive memory usage statistics.
     *
     * Returns detailed information about current memory state including
     * limits, usage, availability, and configuration status. Useful for
     * monitoring and debugging memory-related issues.
     *
     * @return array<string, mixed> Complete memory statistics and configuration
     *
     * @throws DivisionByZeroError If percentage calculation encounters invalid limits
     */
    public function getStatistics(): array
    {
        return [
            'limit' => $this->limit,
            'current' => $this->getCurrentUsage(),
            'peak' => $this->getPeakUsage(),
            'available' => $this->getAvailable(),
            'percentage' => round($this->getUsagePercentage(), 2),
            'is_critical' => $this->isCritical(),
            'auto_cleanup' => $this->autoCleanup,
        ];
    }

    private function assertValidLimit(int $bytes): void
    {
        if ($bytes === -1 || $bytes > 0) {
            return;
        }

        throw new InvalidArgumentException('Memory limit must be -1 or a positive byte count.');
    }
}
