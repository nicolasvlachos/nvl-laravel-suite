<?php

declare(strict_types=1);

namespace Nvl\Csv\Data;

use Carbon\Carbon;
use DivisionByZeroError;
use Exception;
use Nvl\Csv\Enums\CSVErrorLevelEnum;
use Nvl\Csv\Enums\CSVOperationStatusEnum;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Real-time progress tracking data for CSV operations.
 *
 * Provides comprehensive status and metrics for ongoing CSV import/export operations,
 * enabling progress bars, status updates, and performance monitoring.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class CSVProgressData extends Data
{
    use DataTransform;

    /**
     * Create progress tracking data.
     *
     * @param  string  $operationId  Unique operation identifier
     * @param  CSVOperationStatusEnum  $status  Current operation status
     * @param  int  $totalRows  Total number of rows to process
     * @param  int  $processedRows  Number of rows already processed
     * @param  int  $successfulRows  Number of successfully processed rows
     * @param  int  $failedRows  Number of failed rows
     * @param  int  $skippedRows  Number of skipped rows
     * @param  float  $percentComplete  Completion percentage (0-100)
     * @param  float|null|Optional  $estimatedTimeRemaining  Estimated seconds to completion
     * @param  float  $rowsPerSecond  Current processing speed
     * @param  int  $memoryUsage  Current memory usage in bytes
     * @param  int  $peakMemoryUsage  Peak memory usage in bytes
     * @param  string|Optional  $currentBatch  Current batch identifier
     * @param  int|Optional  $batchNumber  Current batch number
     * @param  Carbon  $startedAt  When operation started
     * @param  Carbon|null|Optional  $completedAt  When operation completed (if finished)
     * @param  Carbon  $lastUpdatedAt  When progress was last updated
     * @param  CSVErrorLevelEnum|Optional  $lastErrorLevel  Severity of last error
     * @param  string|Optional  $lastErrorMessage  Last error message
     * @param  array<string, mixed>|Optional  $metadata  Additional operation metadata
     * @return void
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $operationId,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVOperationStatusEnum::class)]
        public readonly CSVOperationStatusEnum $status,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $totalRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $processedRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $successfulRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $failedRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $skippedRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly float $percentComplete,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly float|null|Optional $estimatedTimeRemaining,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly float $rowsPerSecond,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $memoryUsage,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $peakMemoryUsage,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $currentBatch,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $batchNumber,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly Carbon $startedAt,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly Carbon|null|Optional $completedAt,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly Carbon $lastUpdatedAt,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVErrorLevelEnum::class)]
        public readonly CSVErrorLevelEnum|Optional $lastErrorLevel,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $lastErrorMessage,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $metadata,
    ) {}

    /**
     * Create initial progress data for a new operation.
     *
     * @param  string  $operationId  Operation identifier
     * @param  int  $totalRows  Total rows to process
     * @return self Progress data
     */
    public static function initial(string $operationId, int $totalRows): self
    {
        return self::from([
            'operationId' => $operationId,
            'status' => CSVOperationStatusEnum::PENDING,
            'totalRows' => $totalRows,
            'processedRows' => 0,
            'successfulRows' => 0,
            'failedRows' => 0,
            'skippedRows' => 0,
            'percentComplete' => 0.0,
            'rowsPerSecond' => 0.0,
            'memoryUsage' => memory_get_usage(),
            'peakMemoryUsage' => memory_get_peak_usage(),
            'startedAt' => Carbon::now(),
            'lastUpdatedAt' => Carbon::now(),
        ]);
    }

    /**
     * Update progress with new metrics.
     *
     * @param  array<string, mixed>  $metrics  Updated metrics
     * @return self Updated progress data
     *
     * @throws DivisionByZeroError
     * @throws Exception
     */
    public function update(array $metrics): self
    {
        $processedRows = self::resolveNonNegativeInt($metrics['processedRows'] ?? null, $this->processedRows);
        $totalRows = self::resolveNonNegativeInt($metrics['totalRows'] ?? null, $this->totalRows);

        // Calculate percentage
        $percentComplete = $totalRows > 0
            ? min(100.0, round(($processedRows / $totalRows) * 100, 2))
            : 0.0;

        // Calculate speed
        $elapsedSeconds = $this->startedAt->diffInSeconds(Carbon::now());
        $rowsPerSecond = $elapsedSeconds > 0 && $processedRows > 0
            ? round($processedRows / $elapsedSeconds, 2)
            : 0.0;

        // Estimate remaining time
        $estimatedTimeRemaining = null;
        if ($rowsPerSecond > 0 && $totalRows > $processedRows) {
            $remainingRows = $totalRows - $processedRows;
            $estimatedTimeRemaining = (int) round($remainingRows / $rowsPerSecond, 0);
        }

        return self::from(array_merge($this->toArray(), $metrics, [
            'percentComplete' => $percentComplete,
            'rowsPerSecond' => $rowsPerSecond,
            'estimatedTimeRemaining' => $estimatedTimeRemaining,
            'memoryUsage' => memory_get_usage(),
            'peakMemoryUsage' => memory_get_peak_usage(),
            'lastUpdatedAt' => Carbon::now(),
        ]));
    }

    /**
     * Mark operation as completed.
     *
     * @return self Updated progress data
     *
     * @throws Exception
     */
    public function complete(): self
    {
        return self::from(array_merge($this->toArray(), [
            'status' => CSVOperationStatusEnum::COMPLETED,
            'percentComplete' => 100.0,
            'completedAt' => Carbon::now(),
            'lastUpdatedAt' => Carbon::now(),
            'estimatedTimeRemaining' => 0,
        ]));
    }

    /**
     * Mark operation as failed.
     *
     * @param  string  $errorMessage  Error message
     * @param  CSVErrorLevelEnum  $errorLevel  Error level
     * @return self Updated progress data
     *
     * @throws Exception
     */
    public function fail(string $errorMessage, CSVErrorLevelEnum $errorLevel = CSVErrorLevelEnum::ERROR): self
    {
        return self::from(array_merge($this->toArray(), [
            'status' => CSVOperationStatusEnum::FAILED,
            'completedAt' => Carbon::now(),
            'lastUpdatedAt' => Carbon::now(),
            'lastErrorLevel' => $errorLevel,
            'lastErrorMessage' => $errorMessage,
        ]));
    }

    /**
     * Check if operation is still running.
     *
     * @return bool True when operation is running
     */
    public function isRunning(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if operation has finished.
     *
     * @return bool True when operation is terminal
     */
    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Check if operation was successful.
     *
     * @return bool True when operation succeeded
     */
    public function isSuccessful(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * Get human-readable time remaining.
     *
     * @return string Time remaining label
     */
    public function getTimeRemainingFormatted(): string
    {
        if (! ($this->estimatedTimeRemaining instanceof Optional) && $this->estimatedTimeRemaining !== null) {
            if ($this->estimatedTimeRemaining <= 0) {
                return '0 sec';
            }

            $minutes = floor($this->estimatedTimeRemaining / 60);
            $seconds = $this->estimatedTimeRemaining % 60;

            if ($minutes > 0) {
                return sprintf('%d min %d sec', $minutes, $seconds);
            }

            return sprintf('%d sec', $seconds);
        }

        return 'calculating...';
    }

    /**
     * Get processing duration.
     *
     * @return float Duration in seconds
     */
    public function getDuration(): float
    {
        $endTime = $this->completedAt instanceof Carbon ? $this->completedAt : Carbon::now();

        return $this->startedAt->diffInSeconds($endTime);
    }

    /**
     * Get human-readable memory usage.
     *
     * @return string Memory usage label
     */
    public function getMemoryUsageFormatted(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->memoryUsage;

        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get progress bar data for UI.
     *
     * @return array<string, mixed> Progress bar payload
     */
    public function getProgressBarData(): array
    {
        return [
            'percent' => $this->percentComplete,
            'current' => $this->processedRows,
            'total' => $this->totalRows,
            'status' => $this->status->label(),
            'statusColor' => $this->status->getColor(),
            'timeRemaining' => $this->getTimeRemainingFormatted(),
            'speed' => $this->rowsPerSecond.' rows/sec',
        ];
    }

    /**
     * Get summary statistics.
     *
     * @return array<string, mixed> Summary statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'successful_rows' => $this->successfulRows,
            'failed_rows' => $this->failedRows,
            'skipped_rows' => $this->skippedRows,
            'success_rate' => $this->processedRows > 0
                ? round(($this->successfulRows / $this->processedRows) * 100, 2).'%'
                : '0%',
            'processing_speed' => $this->rowsPerSecond.' rows/sec',
            'memory_usage' => $this->getMemoryUsageFormatted(),
            'duration' => $this->getDuration().' seconds',
        ];
    }

    /**
     * Validation rules.
     *
     * @return array<string, array<int, string>> Validation rules
     */
    public static function rules(): array
    {
        return [
            'operationId' => ['required', 'string'],
            'status' => ['required', 'string'],
            'totalRows' => ['required', 'integer', 'min:0'],
            'processedRows' => ['required', 'integer', 'min:0'],
            'successfulRows' => ['required', 'integer', 'min:0'],
            'failedRows' => ['required', 'integer', 'min:0'],
            'skippedRows' => ['required', 'integer', 'min:0'],
            'percentComplete' => ['required', 'numeric', 'min:0', 'max:100'],
            'estimatedTimeRemaining' => ['nullable', 'numeric', 'min:0'],
            'rowsPerSecond' => ['required', 'numeric', 'min:0'],
            'memoryUsage' => ['required', 'integer', 'min:0'],
            'peakMemoryUsage' => ['required', 'integer', 'min:0'],
            'currentBatch' => ['nullable', 'string'],
            'batchNumber' => ['nullable', 'integer', 'min:1'],
            'startedAt' => ['required', 'date'],
            'completedAt' => ['nullable', 'date'],
            'lastUpdatedAt' => ['required', 'date'],
            'lastErrorLevel' => ['nullable', 'string'],
            'lastErrorMessage' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Validation messages.
     *
     * @return array<string, mixed> Validation messages
     */
    public static function messages(): array
    {
        return self::translatedMessages('csv/validation');
    }

    /**
     * Validation attributes.
     *
     * @return array<string, string> Validation attribute labels
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('csv/validation');
    }

    private static function resolveNonNegativeInt(mixed $value, int $fallback): int
    {
        return is_int($value) && $value >= 0 ? $value : $fallback;
    }
}
