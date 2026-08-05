<?php

declare(strict_types=1);

namespace Nvl\Csv\Jobs;

use Closure;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Laravel\SerializableClosure\SerializableClosure;
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\ValueObjects\CSVFieldMapping;
use RuntimeException;
use Throwable;

/**
 * Job for processing a single CSV chunk asynchronously.
 *
 * This job processes a chunk of CSV rows with field mapping,
 * validation, and transformation. It's designed to be part
 * of a larger batch processing operation for very large files.
 */
final class ProcessCSVChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels {
        __serialize as private serializeModels;
        __unserialize as private unserializeModels;
    }

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300; // 5 minutes per chunk

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    private readonly ?SerializableClosure $serializedRowProcessor;

    private readonly ?SerializableClosure $serializedBatchCallback;

    private ?string $chunkPath = null;

    /**
     * Create a new job instance.
     *
     * @param  array<int, array{row_number:int, data: array<string, mixed>}>  $chunkData  Array of row data to process
     * @param  int  $chunkIndex  The index of this chunk in the overall processing
     * @param  array<string, CSVFieldMapping>  $fieldMappings  Field mappings for transformation
     * @param  CSVImportOptionsData  $options  Import options
     * @param  Closure|null  $rowProcessor  Optional row processor callback
     * @param  Closure|null  $batchCallback  Optional batch completion callback
     * @return void
     */
    public function __construct(
        public readonly array $chunkData,
        public readonly int $chunkIndex,
        public readonly array $fieldMappings,
        public readonly CSVImportOptionsData $options,
        public readonly ?Closure $rowProcessor = null,
        public readonly ?Closure $batchCallback = null,
    ) {
        $this->serializedRowProcessor = $rowProcessor === null ? null : new SerializableClosure($rowProcessor);
        $this->serializedBatchCallback = $batchCallback === null ? null : new SerializableClosure($batchCallback);
        $this->onQueue('csv-processing');
    }

    /**
     * Create a lightweight job that loads its rows from a staged chunk.
     *
     * @param  array<string, CSVFieldMapping>  $fieldMappings
     */
    public static function fromStoredChunk(
        string $chunkPath,
        int $chunkIndex,
        array $fieldMappings,
        CSVImportOptionsData $options,
        ?Closure $rowProcessor = null,
        ?Closure $batchCallback = null,
    ): self {
        $job = new self(
            chunkData: [],
            chunkIndex: $chunkIndex,
            fieldMappings: $fieldMappings,
            options: $options,
            rowProcessor: $rowProcessor,
            batchCallback: $batchCallback,
        );
        $job->chunkPath = $chunkPath;

        return $job;
    }

    /**
     * Serialize queue state without placing raw closures in the payload.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $serializedValues = [];

        foreach ($this->serializeModels() as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Serialized queue property names must be strings.');
            }

            $serializedValues[$key] = $value;
        }

        unset($serializedValues['rowProcessor'], $serializedValues['batchCallback']);

        return $serializedValues;
    }

    /**
     * Restore queue state and the compatibility callback properties.
     *
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        $this->unserializeModels($values);
        $this->rowProcessor = $this->serializedRowProcessor?->getClosure();
        $this->batchCallback = $this->serializedBatchCallback?->getClosure();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $batch = $this->batch();
        if ($batch !== null && $batch->cancelled()) {
            $this->deleteStoredChunk();

            return;
        }

        $chunkData = $this->resolveChunkData();
        $startTime = microtime(true);
        $processedRows = 0;
        $failedRows = 0;
        $errors = [];

        Log::info("Processing CSV chunk {$this->chunkIndex}", [
            'chunk_size' => count($chunkData),
            'batch_id' => $this->batch()?->id,
        ]);

        try {
            foreach ($chunkData as $rowInfo) {
                $batch = $this->batch();
                if ($batch !== null && $batch->cancelled()) {
                    break;
                }

                try {
                    $processedRow = $this->processRow($rowInfo);

                    // Call row processor if provided
                    if ($this->rowProcessor !== null) {
                        ($this->rowProcessor)($processedRow, $rowInfo['row_number']);
                    }

                    $processedRows++;

                } catch (Throwable $e) {
                    $failedRows++;
                    $errors[] = [
                        'row_number' => $rowInfo['row_number'],
                        'error' => $e->getMessage(),
                        'data' => $rowInfo['data'],
                    ];

                    Log::warning("Failed to process row {$rowInfo['row_number']}", [
                        'error' => $e->getMessage(),
                        'chunk_index' => $this->chunkIndex,
                    ]);
                }
            }

            $processingTime = microtime(true) - $startTime;

            // Call batch completion callback if provided
            $batch = $this->batch();
            if ($this->batchCallback !== null && ($batch === null || ! $batch->cancelled())) {
                ($this->batchCallback)($this->chunkIndex, $processedRows, $errors);
            }

            Log::info("Completed CSV chunk {$this->chunkIndex}", [
                'processed_rows' => $processedRows,
                'failed_rows' => $failedRows,
                'processing_time' => round($processingTime, 3),
                'rows_per_second' => $processingTime > 0 ? round($processedRows / $processingTime) : 0,
            ]);

            $this->deleteStoredChunk();
        } catch (Throwable $e) {
            Log::error("Critical error processing CSV chunk {$this->chunkIndex}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  Throwable  $exception  Failure exception
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('CSV chunk job failed', [
            'chunk_index' => $this->chunkIndex,
            'batch_id' => $this->batch()?->id,
            'error' => $exception?->getMessage() ?? 'Unknown queue failure.',
            'chunk_size' => count($this->chunkData),
        ]);

        $this->deleteStoredChunk();
    }

    /**
     * Process a single row with field mappings and validation.
     *
     * @param  array{row_number:int, data: array<string, mixed>}  $rowInfo  Row data payload
     * @return array<string, mixed> Processed row data
     *
     * @throws Exception If row processing fails
     */
    private function processRow(array $rowInfo): array
    {
        /** @var array<string, mixed> $rawData */
        $rawData = $rowInfo['data'];
        $processedData = [];

        // If no field mappings defined, return raw data
        if (empty($this->fieldMappings)) {
            return $rawData;
        }

        // Apply field mappings
        foreach ($this->fieldMappings as $csvField => $mapping) {
            $value = $rawData[$csvField] ?? $mapping->defaultValue;

            if ($this->options->shouldValidate() && ! $mapping->validate($value)) {
                $errors = $mapping->getValidationErrors($value);
                throw new Exception(implode(', ', $errors));
            }

            // Apply transformation
            $value = $mapping->apply($value);

            $processedData[$mapping->targetField] = $value;
        }

        return $processedData;
    }

    /**
     * Get the tags for the job.
     *
     * @return array<int, string> Job tags
     */
    public function tags(): array
    {
        $batch = $this->batch();
        $batchId = $batch !== null ? $batch->id : 'unknown';

        return [
            'csv-processing',
            'chunk-'.$this->chunkIndex,
            'batch-'.$batchId,
        ];
    }

    /**
     * Load a staged chunk when the job carries a storage reference.
     *
     * @return list<array{row_number: int, data: array<string, mixed>}>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function resolveChunkData(): array
    {
        if ($this->chunkPath === null) {
            return array_values($this->chunkData);
        }

        $content = Storage::disk('local')->get($this->chunkPath);
        if ($content === null) {
            throw new RuntimeException("Stored CSV chunk '{$this->chunkPath}' does not exist.");
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Stored CSV chunk '{$this->chunkPath}' is invalid.");
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (
                ! is_array($row)
                || ! isset($row['row_number'])
                || ! is_int($row['row_number'])
                || ! isset($row['data'])
                || ! is_array($row['data'])
            ) {
                throw new RuntimeException("Stored CSV chunk '{$this->chunkPath}' has an invalid row.");
            }

            $data = [];
            foreach ($row['data'] as $key => $value) {
                if (is_string($key)) {
                    $data[$key] = $value;
                }
            }

            $rows[] = [
                'row_number' => $row['row_number'],
                'data' => $data,
            ];
        }

        return $rows;
    }

    /**
     * Remove staged chunk data after completion or cancellation.
     */
    private function deleteStoredChunk(): void
    {
        if ($this->chunkPath !== null) {
            Storage::disk('local')->delete($this->chunkPath);
        }
    }
}
