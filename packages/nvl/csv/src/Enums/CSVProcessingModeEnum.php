<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * CSV processing strategy enumeration for different memory and performance requirements.
 *
 * Each mode provides different trade-offs between speed, memory usage, and scalability:
 * - Memory modes for small files requiring fast access
 * - Streaming modes for large files with memory constraints
 * - Batch/Queue modes for background processing of huge datasets
 */
enum CSVProcessingModeEnum: string
{
    // Fast processing modes - load data into memory
    case MEMORY = 'memory';         // Load entire file for fastest access

    // Memory-efficient streaming modes
    case STREAM = 'stream';         // Process one row at a time
    case LAZY = 'lazy';             // On-demand loading with generators
    case CHUNKED = 'chunked';       // Process in configurable chunks

    // High-volume processing modes
    case BATCH = 'batch';           // Large batch operations
    case QUEUE = 'queue';           // Asynchronous background processing

    /**
     * Get the default chunk size for this processing mode.
     *
     * Returns the recommended number of rows to process in each iteration.
     * Balances memory usage with processing efficiency for each mode:
     * - 0 means no chunking (process entire file)
     * - 1 means row-by-row processing
     * - Higher values for batch operations
     *
     * @return int Default number of rows per chunk
     */
    public function getDefaultChunkSize(): int
    {
        return match ($this) {
            self::MEMORY => 0,    // No chunking - process entire file
            self::STREAM => 1,    // Row-by-row processing for minimal memory
            self::LAZY => 100,    // Small chunks for generator-based loading
            self::CHUNKED => 500, // Medium chunks for balanced performance
            self::BATCH => 1000,  // Large chunks for bulk operations
            self::QUEUE => 250,   // Moderate chunks for queue job processing
        };
    }

    /**
     * Check if this processing mode supports row-level filtering.
     *
     * Filtering allows skipping rows based on criteria during processing.
     * Some modes (like BATCH) process all rows and don't support filtering.
     *
     * @return bool True if filtering is supported
     */
    public function supportsFiltering(): bool
    {
        return match ($this) {
            self::MEMORY => true,  // Full filtering support with in-memory data
            self::STREAM => true,  // Can filter during row-by-row processing
            self::LAZY => true,    // Generator-based filtering supported
            self::CHUNKED => true, // Can filter within each chunk
            self::BATCH => false,  // Batch processing typically handles all rows
            self::QUEUE => true,   // Queue jobs can filter before processing
        };
    }

    /**
     * Check if this mode supports random access to rows.
     *
     * Random access allows jumping to any row position without
     * processing all previous rows. Only available when entire
     * dataset is loaded into memory.
     *
     * @return bool True if random row access is supported
     */
    public function supportsRandomAccess(): bool
    {
        return match ($this) {
            self::MEMORY => true,  // Full dataset in memory allows random access
            self::STREAM => false, // Sequential processing only
            self::LAZY => false,   // Generator-based - sequential access only
            self::CHUNKED => false,// Processes chunks sequentially
            self::BATCH => false,  // Batch processing is sequential
            self::QUEUE => false,  // Queue processing is inherently sequential
        };
    }

    /**
     * Get the recommended memory limit in megabytes for this mode.
     *
     * Returns conservative memory limits based on typical usage patterns:
     * - Higher limits for in-memory modes that store entire dataset
     * - Lower limits for streaming modes that process incrementally
     * - Moderate limits for chunked processing
     *
     * @return int Memory limit in megabytes
     */
    public function getRecommendedMemoryLimit(): int
    {
        return match ($this) {
            self::MEMORY => 512,  // High memory for entire dataset storage
            self::STREAM => 32,   // Minimal memory - only current row
            self::LAZY => 64,     // Low memory - small generator buffers
            self::CHUNKED => 128, // Moderate memory for chunk processing
            self::BATCH => 256,   // Higher memory for large batch operations
            self::QUEUE => 64,    // Queue jobs need minimal resident memory
        };
    }

    /**
     * Check if this processing mode can handle large CSV files efficiently.
     *
     * Large files (>100MB or >100k rows) require memory-efficient processing.
     * In-memory modes become impractical for large datasets due to memory
     * constraints and performance degradation.
     *
     * @return bool True if suitable for processing large files
     */
    public function isSuitableForLargeFiles(): bool
    {
        return match ($this) {
            self::MEMORY => false, // Limited by available system memory
            self::STREAM => true,  // Constant memory usage regardless of file size
            self::LAZY => true,    // Generator-based scaling with minimal memory
            self::CHUNKED => true, // Chunk size controls memory, scales well
            self::BATCH => true,   // Large batch processing handles huge files
            self::QUEUE => true,   // Background processing scales horizontally
        };
    }

    /**
     * Get user-friendly display label for this processing mode.
     *
     * Provides clear, concise names suitable for dropdowns,
     * configuration interfaces, and user documentation.
     *
     * @return string Human-readable processing mode name
     */
    public function label(): string
    {
        return match ($this) {
            self::MEMORY => 'In-Memory',        // Load all data into memory
            self::STREAM => 'Streaming',        // Row-by-row processing
            self::LAZY => 'Lazy Loading',       // On-demand data loading
            self::CHUNKED => 'Chunked',         // Process in chunks
            self::BATCH => 'Batch Processing',  // Large batch operations
            self::QUEUE => 'Queue Processing',  // Background job processing
        };
    }

    /**
     * Get detailed description explaining this processing mode.
     *
     * Provides comprehensive explanation of how the mode works,
     * its characteristics, and when to use it. Suitable for
     * tooltips, help text, and documentation.
     *
     * @return string Detailed mode description
     *
     * // 'Load data on-demand using generators'
     */
    public function description(): string
    {
        return match ($this) {
            self::MEMORY => 'Load entire file into memory for fast processing',
            self::STREAM => 'Process file row by row with minimal memory usage',
            self::LAZY => 'Load data on-demand using generators',
            self::CHUNKED => 'Process file in configurable chunks',
            self::BATCH => 'Process file in large batches for bulk operations',
            self::QUEUE => 'Process file asynchronously using queue jobs',
        };
    }

    /**
     * Check if this processing mode requires queue system configuration.
     *
     * Queue-based processing requires Laravel queue workers and
     * job configuration for background processing of large files.
     *
     * @return bool True if queue system is required
     */
    public function requiresQueue(): bool
    {
        return $this === self::QUEUE;
    }

    /**
     * Get comprehensive performance characteristics for this processing mode.
     *
     * Returns a detailed breakdown of performance aspects:
     * - speed: Processing speed relative to other modes
     * - memory: Memory usage requirements
     * - scalability: Ability to handle increasing file sizes
     *
     * @return array{speed: string, memory: string, scalability: string}
     *                                                                   Performance characteristics with descriptive ratings
     *
     * // ['speed' => 'fastest', 'memory' => 'highest', 'scalability' => 'limited']
     */
    public function getPerformanceCharacteristics(): array
    {
        return match ($this) {
            self::MEMORY => [
                'speed' => 'fastest',
                'memory' => 'highest',
                'scalability' => 'limited',
            ],
            self::STREAM => [
                'speed' => 'slow',
                'memory' => 'lowest',
                'scalability' => 'excellent',
            ],
            self::LAZY => [
                'speed' => 'moderate',
                'memory' => 'low',
                'scalability' => 'good',
            ],
            self::CHUNKED => [
                'speed' => 'good',
                'memory' => 'moderate',
                'scalability' => 'good',
            ],
            self::BATCH => [
                'speed' => 'good',
                'memory' => 'moderate',
                'scalability' => 'very good',
            ],
            self::QUEUE => [
                'speed' => 'variable',
                'memory' => 'low',
                'scalability' => 'excellent',
            ],
        };
    }
}
