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
use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Services\CSVHandlerRegistry;
use Nvl\Csv\Services\CSVWorkStore;
use Nvl\Csv\ValueObjects\CSVFieldMapping;
use Nvl\Csv\ValueObjects\CSVWorkReference;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use RuntimeException;
use Throwable;

/**
 * Job for processing a single CSV chunk asynchronously.
 *
 * This job processes a chunk of CSV rows with field mapping,
 * validation, and transformation. It's designed to be part
 * of a larger batch processing operation for very large files.
 */
final class ProcessCSVChunkJob implements ShouldQueue, TenantQueuedJob
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

    private ?CSVWorkReference $tenantWork = null;

    private readonly TenantJobEnvelope $envelope;

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
        public readonly ?CSVImportOptionsData $options,
        public readonly ?Closure $rowProcessor = null,
        public readonly ?Closure $batchCallback = null,
        ?TenantJobEnvelope $envelope = null,
    ) {
        $this->envelope = $envelope ?? new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Disabled));
        $this->serializedRowProcessor = $rowProcessor === null ? null : new SerializableClosure($rowProcessor);
        $this->serializedBatchCallback = $batchCallback === null ? null : new SerializableClosure($batchCallback);
        $this->onQueue('csv-processing');
    }

    /** Create a scalar-reference tenant job without serializing rows or callbacks. */
    public static function fromTenantWork(CSVWorkReference $work, int $chunkIndex, TenantJobEnvelope $envelope): self
    {
        $job = new self([], $chunkIndex, [], null, null, null, $envelope);
        $job->tenantWork = $work;

        return $job;
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
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
    public function handle(?CSVWorkStore $workStore = null, ?CSVHandlerRegistry $handlers = null): void
    {
        $batch = $this->batch();
        if ($batch !== null && $batch->cancelled()) {
            $this->deleteStoredChunk();
            if ($workStore !== null) {
                $this->deleteTenantChunk($workStore);
            }

            return;
        }

        [$chunkData, $options, $fieldMappings] = $this->tenantWork instanceof CSVWorkReference
            ? $this->resolveTenantChunkData($workStore ?? throw new TenantBoundaryViolation('Tenant CSV work store is unavailable.'))
            : [$this->resolveChunkData(), $this->options ?? throw new RuntimeException('CSV import options are missing.'), $this->fieldMappings];
        $handler = $this->tenantWork instanceof CSVWorkReference
            ? ($handlers ?? throw new TenantBoundaryViolation('Tenant CSV handler registry is unavailable.'))->resolve($this->tenantWork->handlerAlias)
            : null;
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
                    $processedRow = $this->processRow($rowInfo, $options, $fieldMappings);

                    // Call row processor if provided
                    if ($this->rowProcessor !== null) {
                        ($this->rowProcessor)($processedRow, $rowInfo['row_number']);
                    }
                    $handler?->process($processedRow, $rowInfo['row_number']);

                    $processedRows++;

                } catch (Throwable $e) {
                    if ($handler !== null) {
                        throw $e;
                    }
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
            if ($workStore !== null) {
                $this->deleteTenantChunk($workStore);
            }
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
    private function processRow(array $rowInfo, CSVImportOptionsData $options, array $fieldMappings): array
    {
        /** @var array<string, mixed> $rawData */
        $rawData = $rowInfo['data'];
        $processedData = [];

        // If no field mappings defined, return raw data
        if (empty($fieldMappings)) {
            return $rawData;
        }

        // Apply field mappings
        foreach ($fieldMappings as $csvField => $mapping) {
            $value = $rawData[$csvField] ?? $mapping->defaultValue;

            if ($options->shouldValidate() && ! $mapping->validate($value)) {
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

    /** @return array{0:list<array{row_number:int,data:array<string,mixed>}>,1:CSVImportOptionsData,2:array<string,CSVFieldMapping>} */
    private function resolveTenantChunkData(CSVWorkStore $workStore): array
    {
        $work = $this->tenantWork ?? throw new TenantBoundaryViolation('Tenant CSV work reference is missing.');
        $manifest = $workStore->read($work);
        $chunks = $manifest['chunks'] ?? null;
        $options = $manifest['options'] ?? null;
        $mappings = $manifest['mappings'] ?? null;
        if (! is_array($chunks) || ! is_array($options) || ! is_array($mappings)) {
            throw new TenantBoundaryViolation('Tenant CSV manifest shape is invalid.');
        }
        $chunk = null;
        foreach ($chunks as $candidate) {
            if (is_array($candidate) && ($candidate['index'] ?? null) === $this->chunkIndex) {
                $chunk = $candidate;
                break;
            }
        }
        $expectedPath = dirname($work->manifestPath).'/chunks/'.$this->chunkIndex.'.json';
        if (! is_array($chunk) || ($chunk['path'] ?? null) !== $expectedPath || ! is_string($chunk['sha256'] ?? null)) {
            throw new TenantBoundaryViolation('Tenant CSV chunk reference is invalid.');
        }
        $raw = Storage::disk($work->disk)->get($expectedPath);
        if (! is_string($raw) || ! hash_equals($chunk['sha256'], hash('sha256', $raw))) {
            throw new TenantBoundaryViolation('Tenant CSV chunk checksum is invalid.');
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new TenantBoundaryViolation('Tenant CSV chunk payload is invalid.');
        }

        return [
            $this->normalizeRows($decoded),
            CSVImportOptionsData::from($options),
            $this->restoreMappings($mappings),
        ];
    }

    /** @param array<int,mixed> $rows @return list<array{row_number:int,data:array<string,mixed>}> */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['row_number'] ?? null) || ! is_array($row['data'] ?? null)) {
                throw new TenantBoundaryViolation('Tenant CSV row payload is invalid.');
            }
            $data = [];
            foreach ($row['data'] as $key => $value) {
                if (is_string($key)) {
                    $data[$key] = $value;
                }
            }
            $normalized[] = ['row_number' => $row['row_number'], 'data' => $data];
        }

        return $normalized;
    }

    /** @param array<int|string,mixed> $rows @return array<string,CSVFieldMapping> */
    private function restoreMappings(array $rows): array
    {
        $restored = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['source_field'] ?? null) || ! is_string($row['target_field'] ?? null)
                || ($row['has_transformer'] ?? false) !== false || ($row['validators_count'] ?? 0) !== 0) {
                throw new TenantBoundaryViolation('Tenant CSV field mapping is invalid.');
            }
            $type = is_string($row['type'] ?? null) ? CSVTypeEnum::tryFrom($row['type']) : null;
            $mapping = new CSVFieldMapping(
                sourceField: $row['source_field'],
                targetField: $row['target_field'],
                type: $type,
                required: (bool) ($row['required'] ?? false),
                defaultValue: $row['default_value'] ?? null,
                unique: (bool) ($row['unique'] ?? false),
                nullable: (bool) ($row['nullable'] ?? true),
                format: is_string($row['format'] ?? null) ? $row['format'] : null,
                metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
            $restored[$mapping->sourceField] = $mapping;
        }

        return $restored;
    }

    private function deleteTenantChunk(CSVWorkStore $workStore): void
    {
        if (! $this->tenantWork instanceof CSVWorkReference) {
            return;
        }
        $workStore->read($this->tenantWork);
        Storage::disk($this->tenantWork->disk)->delete(dirname($this->tenantWork->manifestPath).'/chunks/'.$this->chunkIndex.'.json');
    }
}
