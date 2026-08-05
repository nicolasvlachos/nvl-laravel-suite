<?php

declare(strict_types=1);

namespace Nvl\Csv\ValueObjects;

use Carbon\Carbon;

/**
 * Value object representing CSV import result.
 *
 * Contains comprehensive information about a completed CSV import operation,
 * including statistics, errors, warnings, and processing details.
 */
final readonly class CSVImportResult
{
    /**
     * Create a new CSV import result.
     *
     * @param  int  $totalRows  Total number of rows in CSV
     * @param  int  $processedRows  Number of rows processed
     * @param  int  $successfulRows  Number of successfully imported rows
     * @param  int  $failedRows  Number of failed rows
     * @param  int  $skippedRows  Number of skipped rows
     * @param  array<int, array<string, mixed>>  $failedRowsData  Data from failed rows
     * @param  float  $processingTime  Processing time in seconds
     * @param  Carbon  $startedAt  When import started
     * @param  Carbon|null  $completedAt  When import completed
     * @param  array<int, string>  $errors  Import errors
     * @param  array<int, string>  $warnings  Import warnings
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    public function __construct(
        public int $totalRows,
        public int $processedRows,
        public int $successfulRows,
        public int $failedRows,
        public int $skippedRows,
        public array $failedRowsData = [],
        public float $processingTime = 0.0,
        public Carbon $startedAt = new Carbon,
        public ?Carbon $completedAt = null,
        public array $errors = [],
        public array $warnings = [],
        public array $metadata = [],
    ) {}

    /**
     * Create initial result.
     *
     * @param  int  $totalRows  Total number of rows to process
     */
    public static function initial(int $totalRows): self
    {
        return new self(
            totalRows: $totalRows,
            processedRows: 0,
            successfulRows: 0,
            failedRows: 0,
            skippedRows: 0,
            startedAt: Carbon::now(),
        );
    }

    /**
     * Create completed result.
     *
     * @param  array<string, mixed>  $stats  Import statistics
     */
    public static function completed(array $stats): self
    {
        $startedAt = self::resolveCarbon($stats['started_at'] ?? null);
        $completedAt = self::resolveCarbon($stats['completed_at'] ?? null);

        $failedRowsData = self::resolveFailedRowsData($stats['failed_rows_data'] ?? []);
        $errors = self::resolveStringList($stats['errors'] ?? []);
        $warnings = self::resolveStringList($stats['warnings'] ?? []);
        $metadata = self::resolveMetadata($stats['metadata'] ?? []);
        $processingTime = $stats['processing_time'] ?? null;
        $resolvedProcessingTime = is_int($processingTime) || is_float($processingTime)
            ? max(0.0, (float) $processingTime)
            : max(0.0, (float) $startedAt->diffInSeconds($completedAt));

        return new self(
            totalRows: self::resolveInt($stats['total_rows'] ?? null),
            processedRows: self::resolveInt($stats['processed_rows'] ?? null),
            successfulRows: self::resolveInt($stats['successful_rows'] ?? null),
            failedRows: self::resolveInt($stats['failed_rows'] ?? null),
            skippedRows: self::resolveInt($stats['skipped_rows'] ?? null),
            failedRowsData: $failedRowsData,
            processingTime: $resolvedProcessingTime,
            startedAt: $startedAt,
            completedAt: $completedAt,
            errors: $errors,
            warnings: $warnings,
            metadata: $metadata,
        );
    }

    private static function resolveInt(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function resolveCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value)) {
            return Carbon::parse($value);
        }

        return Carbon::now();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolveFailedRowsData(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $failedRowsData = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedRow = [];
            foreach ($row as $key => $rowValue) {
                if (is_string($key)) {
                    $normalizedRow[$key] = $rowValue;
                }
            }

            $failedRowsData[] = $normalizedRow;
        }

        return $failedRowsData;
    }

    /**
     * @return array<int, string>
     */
    private static function resolveStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $metadata = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $metadata[$key] = $item;
            }
        }

        return $metadata;
    }

    /**
     * Check if import was successful.
     *
     * @return bool True if no failures or errors occurred
     */
    public function isSuccessful(): bool
    {
        return $this->failedRows === 0 && empty($this->errors);
    }

    /**
     * Check if import was partially successful.
     *
     * @return bool True if some rows succeeded and some failed
     */
    public function isPartiallySuccessful(): bool
    {
        return $this->successfulRows > 0 && $this->failedRows > 0;
    }

    /**
     * Check if import failed completely.
     *
     * @return bool True if no rows were successfully imported
     */
    public function isCompleteFailure(): bool
    {
        return $this->successfulRows === 0 && $this->processedRows > 0;
    }

    /**
     * Check if import has warnings.
     *
     * @return bool True if warnings exist
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * Check if import has errors.
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Get success rate percentage.
     *
     * @return float Success rate as percentage (0-100)
     */
    public function getSuccessRate(): float
    {
        if ($this->processedRows === 0) {
            return 0.0;
        }

        return round(($this->successfulRows / $this->processedRows) * 100, 2);
    }

    /**
     * Get failure rate percentage.
     *
     * @return float Failure rate as percentage (0-100)
     */
    public function getFailureRate(): float
    {
        if ($this->processedRows === 0) {
            return 0.0;
        }

        return round(($this->failedRows / $this->processedRows) * 100, 2);
    }

    /**
     * Get skip rate percentage.
     *
     * @return float Skip rate as percentage (0-100)
     */
    public function getSkipRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return round(($this->skippedRows / $this->totalRows) * 100, 2);
    }

    /**
     * Get processing time in seconds.
     *
     * @return float Processing time rounded to 2 decimal places
     */
    public function getProcessingTimeInSeconds(): float
    {
        return round($this->processingTime, 2);
    }

    /**
     * Get rows per second processing rate.
     *
     * @return float Processing rate, 0 if no processing time
     */
    public function getRowsPerSecond(): float
    {
        if ($this->processingTime <= 0) {
            return 0;
        }

        return round($this->processedRows / $this->processingTime, 2);
    }

    /**
     * Get human-readable summary.
     *
     * @return string Formatted summary string
     */
    public function getSummary(): string
    {
        $parts = [];

        if ($this->isSuccessful()) {
            $parts[] = "Successfully imported {$this->successfulRows} rows";
        } elseif ($this->isPartiallySuccessful()) {
            $parts[] = "Imported {$this->successfulRows} rows";
            $parts[] = "{$this->failedRows} failed";
        } else {
            $parts[] = 'Import failed';
            $parts[] = "{$this->failedRows} rows failed";
        }

        if ($this->skippedRows > 0) {
            $parts[] = "{$this->skippedRows} skipped";
        }

        $parts[] = "in {$this->getProcessingTimeInSeconds()}s";

        return implode(', ', $parts);
    }

    /**
     * Get import statistics.
     *
     * @return array<string, mixed> Key statistics with formatted values
     */
    public function getStatistics(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'successful_rows' => $this->successfulRows,
            'failed_rows' => $this->failedRows,
            'skipped_rows' => $this->skippedRows,
            'success_rate' => $this->getSuccessRate().'%',
            'failure_rate' => $this->getFailureRate().'%',
            'skip_rate' => $this->getSkipRate().'%',
            'processing_time' => $this->getProcessingTimeInSeconds().'s',
            'rows_per_second' => $this->getRowsPerSecond(),
        ];
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed> Complete import result data
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'successful_rows' => $this->successfulRows,
            'failed_rows' => $this->failedRows,
            'skipped_rows' => $this->skippedRows,
            'failed_rows_data' => $this->failedRowsData,
            'processing_time' => $this->processingTime,
            'started_at' => $this->startedAt->toIso8601String(),
            'completed_at' => $this->completedAt?->toIso8601String(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
            'statistics' => $this->getStatistics(),
            'summary' => $this->getSummary(),
            'successful' => $this->isSuccessful(),
            'partially_successful' => $this->isPartiallySuccessful(),
            'complete_failure' => $this->isCompleteFailure(),
        ];
    }
}
