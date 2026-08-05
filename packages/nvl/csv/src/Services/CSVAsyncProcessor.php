<?php

declare(strict_types=1);

namespace Nvl\Csv\Services;

use Closure;
use DivisionByZeroError;
use Exception;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\Jobs\ProcessCSVChunkJob;
use Nvl\Csv\ValueObjects\CSVFieldMapping;
use RuntimeException;
use Throwable;

/**
 * Asynchronous CSV processing service for very large files.
 *
 * This service breaks large CSV files into chunks and processes them
 * asynchronously using Laravel's queue system with batching support.
 * Ideal for files with 100,000+ rows that would exceed memory limits
 * or time limits in synchronous processing.
 */
final class CSVAsyncProcessor
{
    private ?CSVImportOptionsData $options = null;

    private ?string $filePath = null;

    private int $chunkSize;

    private ?Closure $rowProcessor = null;

    private ?Closure $progressCallback = null;

    private ?Closure $batchCallback = null;

    private ?Closure $completionCallback = null;

    /** @var array<string, CSVFieldMapping> */
    private array $fieldMappings = [];

    /**
     * Create a new async processor instance.
     */
    public function __construct()
    {
        $this->chunkSize = 1000;
    }

    /**
     * Create a new async processor instance.
     *
     * @return self Processor instance
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Set the file path to process.
     *
     * @param  string  $filePath  CSV file path
     * @return self Processor instance
     */
    public function fromFile(string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }

    /**
     * Set processing options.
     *
     * @param  CSVImportOptionsData  $options  Import options
     * @return self Processor instance
     */
    public function withOptions(CSVImportOptionsData $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Set the chunk size for batch processing.
     *
     * @param  int  $size  Number of rows per chunk (default: 1000)
     * @return self Processor instance
     */
    public function withChunkSize(int $size): self
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }

        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Add field mapping for data transformation.
     *
     * @param  string  $csvField  CSV field name
     * @param  string  $targetField  Target field name
     * @param  CSVFieldMapping|null  $mapping  Field mapping configuration
     * @return self Processor instance
     */
    public function mapField(string $csvField, string $targetField, ?CSVFieldMapping $mapping = null): self
    {
        $this->fieldMappings[$csvField] = $mapping ?? CSVFieldMapping::simple($csvField, $targetField);

        return $this;
    }

    /**
     * Set row processor callback for each processed row.
     *
     * @param  Closure  $processor  Row processor callback
     * @return self Processor instance
     */
    public function processRow(Closure $processor): self
    {
        $this->rowProcessor = $processor;

        return $this;
    }

    /**
     * Set progress callback for tracking processing progress.
     *
     * @param  Closure  $callback  Function that receives the current Laravel batch
     * @return self Processor instance
     */
    public function onProgress(Closure $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Set batch completion callback.
     *
     * @param  Closure  $callback  Function that receives (chunkNumber, processedRows, errors)
     * @return self Processor instance
     */
    public function onBatchComplete(Closure $callback): self
    {
        $this->batchCallback = $callback;

        return $this;
    }

    /**
     * Set completion callback for when entire file is processed.
     *
     * @param  Closure  $callback  Function that receives the completed Laravel batch
     * @return self Processor instance
     */
    public function onComplete(Closure $callback): self
    {
        $this->completionCallback = $callback;

        return $this;
    }

    /**
     * Process the CSV file asynchronously.
     *
     * @return Batch Laravel batch instance for monitoring progress
     *
     * @throws RuntimeException
     * @throws Exception
     * @throws DivisionByZeroError
     */
    public function processAsync(): Batch
    {
        if ($this->filePath === null || $this->options === null) {
            throw new RuntimeException('File path and options must be set before processing');
        }
        $staged = $this->createJobs();
        $jobs = $staged['jobs'];
        $chunkDirectory = $staged['directory'];

        $batch = Bus::batch($jobs)
            ->name('CSV Processing: '.basename($this->filePath))
            ->allowFailures()
            ->onQueue('csv-processing');

        if ($this->progressCallback !== null) {
            $batch->progress($this->progressCallback);
        }

        $completionCallback = $this->completionCallback;
        $batch->finally(function (Batch $completedBatch) use ($chunkDirectory, $completionCallback): void {
            Storage::disk('local')->deleteDirectory($chunkDirectory);

            if ($completionCallback !== null) {
                $completionCallback($completedBatch);
            }
        });

        try {
            return $batch->dispatch();
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory($chunkDirectory);

            throw $exception;
        }
    }

    /**
     * Process the CSV file asynchronously with real-time progress tracking.
     *
     * This method provides a higher-level interface with built-in progress tracking
     * and status updates. Returns a batch ID that can be used to monitor progress.
     *
     * @return string Batch ID for monitoring
     *
     * @throws RuntimeException
     * @throws Exception
     * @throws DivisionByZeroError
     */
    public function processAsyncWithTracking(): string
    {
        $batch = $this->processAsync();

        // Store batch metadata for tracking
        $this->storeBatchMetadata($batch->id, [
            'file_path' => $this->filePath,
            'chunk_size' => $this->chunkSize,
            'total_jobs' => $batch->totalJobs,
            'started_at' => now(),
            'status' => 'processing',
        ]);

        return $batch->id;
    }

    /**
     * Get the status of an async processing batch.
     *
     * @param  string  $batchId  The batch ID returned from processAsyncWithTracking()
     * @return array{
     *     id?: string,
     *     status: string,
     *     progress?: array{
     *         processed_jobs: int,
     *         pending_jobs: int,
     *         failed_jobs: int,
     *         total_jobs: int,
     *         progress_percentage: int
     *     },
     *     timing?: array<string, mixed>,
     *     metadata?: array<string, mixed>|null
     * }
     *
     * @throws RuntimeException
     */
    public function getBatchStatus(string $batchId): array
    {
        $batch = Bus::findBatch($batchId);

        if ($batch === null) {
            return ['status' => 'not_found'];
        }

        $metadata = $this->getBatchMetadata($batchId);

        return [
            'id' => $batch->id,
            'status' => $this->determineBatchStatus($batch),
            'progress' => [
                'processed_jobs' => $batch->processedJobs(),
                'pending_jobs' => $batch->pendingJobs,
                'failed_jobs' => $batch->failedJobs,
                'total_jobs' => $batch->totalJobs,
                'progress_percentage' => $batch->progress(),
            ],
            'timing' => [
                'created_at' => $batch->createdAt,
                'finished_at' => $batch->finishedAt,
                'cancelled_at' => $batch->cancelledAt,
            ],
            'metadata' => $metadata,
        ];
    }

    /**
     * Cancel an async processing batch.
     *
     * @param  string  $batchId  The batch ID to cancel
     * @return bool True if successfully cancelled
     *
     * @throws RuntimeException
     */
    public function cancelBatch(string $batchId): bool
    {
        $batch = Bus::findBatch($batchId);

        if ($batch === null) {
            return false;
        }

        $batch->cancel();

        // Update metadata
        $this->updateBatchMetadata($batchId, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return true;
    }

    /**
     * Stage bounded chunks and create lightweight queued jobs.
     *
     * @return array{jobs: list<ProcessCSVChunkJob>, directory: string}
     *
     * @throws Exception
     * @throws DivisionByZeroError
     * @throws JsonException
     */
    private function createJobs(): array
    {
        if ($this->filePath === null || $this->options === null) {
            throw new RuntimeException('File path and options must be set before creating chunks');
        }

        $import = CSVImport::make()
            ->withOptions($this->options)
            ->fromFile($this->filePath);

        $directory = 'csv_processing_chunks/'.Str::uuid()->toString();
        $jobs = [];
        $currentChunk = [];
        $chunkIndex = 0;

        try {
            foreach ($import->stream() as $rowNumber => $row) {
                $currentChunk[] = [
                    'row_number' => $rowNumber,
                    'data' => $row,
                ];

                if (count($currentChunk) >= $this->chunkSize) {
                    $jobs[] = $this->stageJob($directory, $chunkIndex, $currentChunk);
                    $currentChunk = [];
                    $chunkIndex++;
                }
            }

            if (! empty($currentChunk)) {
                $jobs[] = $this->stageJob($directory, $chunkIndex, $currentChunk);
            }
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory($directory);

            throw $exception;
        }

        return [
            'jobs' => $jobs,
            'directory' => $directory,
        ];
    }

    /**
     * Persist one chunk and create a job carrying only its storage reference.
     *
     * @param  list<array{row_number: int, data: array<string, mixed>}>  $chunk
     *
     * @throws JsonException
     */
    private function stageJob(string $directory, int $chunkIndex, array $chunk): ProcessCSVChunkJob
    {
        $chunkPath = "{$directory}/{$chunkIndex}.json";
        $stored = Storage::disk('local')->put(
            $chunkPath,
            json_encode($chunk, JSON_THROW_ON_ERROR),
        );
        if (! $stored) {
            throw new RuntimeException("Unable to stage CSV chunk '{$chunkPath}'.");
        }

        return ProcessCSVChunkJob::fromStoredChunk(
            chunkPath: $chunkPath,
            chunkIndex: $chunkIndex,
            fieldMappings: $this->fieldMappings,
            options: $this->options
                ?? throw new RuntimeException('CSV import options are not configured.'),
            rowProcessor: $this->rowProcessor,
            batchCallback: $this->batchCallback,
        );
    }

    /**
     * Store batch metadata for tracking.
     *
     * @param  string  $batchId  Batch identifier
     * @param  array<string, mixed>  $metadata  Metadata to store
     *
     * @throws RuntimeException
     */
    private function storeBatchMetadata(string $batchId, array $metadata): void
    {
        $stored = Storage::disk('local')->put(
            "csv_batch_metadata/{$batchId}.json",
            json_encode($metadata, JSON_THROW_ON_ERROR),
        );

        if (! $stored) {
            throw new RuntimeException("Unable to store metadata for CSV batch '{$batchId}'.");
        }
    }

    /**
     * Get batch metadata.
     *
     * @param  string  $batchId  Batch identifier
     * @return array<string, mixed>|null Batch metadata
     */
    private function getBatchMetadata(string $batchId): ?array
    {
        $path = "csv_batch_metadata/{$batchId}.json";

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $content = Storage::disk('local')->get($path);
        if ($content === null) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }

        $metadata = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Update batch metadata.
     *
     * @param  string  $batchId  Batch identifier
     * @param  array<string, mixed>  $updates  Metadata updates
     */
    private function updateBatchMetadata(string $batchId, array $updates): void
    {
        $existing = $this->getBatchMetadata($batchId) ?? [];
        $updated = array_merge($existing, $updates);

        $this->storeBatchMetadata($batchId, $updated);
    }

    /**
     * Determine the current status of a batch.
     *
     * @param  Batch  $batch  Batch instance
     * @return string Batch status
     */
    private function determineBatchStatus(Batch $batch): string
    {
        if ($batch->cancelled()) {
            return 'cancelled';
        }

        if ($batch->finished()) {
            return $batch->hasFailures() ? 'completed_with_failures' : 'completed';
        }

        if ($batch->failedJobs > 0) {
            return 'processing_with_failures';
        }

        return 'processing';
    }
}
