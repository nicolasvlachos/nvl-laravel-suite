<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Jobs\ProcessCSVChunkJob;
use Nvl\Csv\Services\CSVAsyncProcessor;
use Nvl\Csv\ValueObjects\CSVFieldMapping;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('async-results');
});

it('serializes callback-bearing mappings and chunk jobs safely', function (): void {
    $mapping = CSVFieldMapping::withTransformer(
        'name',
        'display_name',
        fn (mixed $value): string => strtoupper((string) $value),
    );
    $job = new ProcessCSVChunkJob(
        chunkData: [
            ['row_number' => 1, 'data' => ['name' => 'Jane']],
        ],
        chunkIndex: 4,
        fieldMappings: ['name' => $mapping],
        options: CSVImportOptionsData::from(['filePath' => '/tmp/source.csv']),
        rowProcessor: function (array $row, int $rowNumber): void {
            Storage::disk('async-results')->put(
                "rows/{$rowNumber}.json",
                json_encode($row, JSON_THROW_ON_ERROR),
            );
        },
        batchCallback: function (int $chunk, int $processed, array $errors): void {
            Storage::disk('async-results')->put(
                'batch.json',
                json_encode(compact('chunk', 'processed', 'errors'), JSON_THROW_ON_ERROR),
            );
        },
    );

    $restored = unserialize(serialize($job));
    expect($restored)->toBeInstanceOf(ProcessCSVChunkJob::class);

    $restored->handle();

    expect(Storage::disk('async-results')->json('rows/1.json'))->toBe(['display_name' => 'JANE'])
        ->and(Storage::disk('async-results')->json('batch.json'))->toMatchArray([
            'chunk' => 4,
            'processed' => 1,
            'errors' => [],
        ])
        ->and($restored->tags())->toContain('csv-processing', 'chunk-4', 'batch-unknown')
        ->and($restored->backoff)->toBe([1, 5, 15]);
});

it('captures row-level failures without failing an entire chunk', function (): void {
    $job = new ProcessCSVChunkJob(
        chunkData: [
            ['row_number' => 1, 'data' => ['age' => 'invalid']],
            ['row_number' => 2, 'data' => ['age' => '42']],
        ],
        chunkIndex: 0,
        fieldMappings: [
            'age' => CSVFieldMapping::typed('age', 'age', CSVTypeEnum::INTEGER, true),
        ],
        options: CSVImportOptionsData::from(['filePath' => '/tmp/source.csv']),
        batchCallback: function (int $chunk, int $processed, array $errors): void {
            Storage::disk('async-results')->put(
                'failed-batch.json',
                json_encode(compact('chunk', 'processed', 'errors'), JSON_THROW_ON_ERROR),
            );
        },
    );

    $job->handle();
    $summary = Storage::disk('async-results')->json('failed-batch.json');

    expect($summary['processed'])->toBe(1)
        ->and($summary['errors'])->toHaveCount(1)
        ->and($summary['errors'][0]['row_number'])->toBe(1);
});

it('stages bounded chunks instead of embedding full source data in jobs', function (): void {
    Bus::fake();
    $path = $this->temporaryCsv("id,name\n1,A\n2,B\n3,C\n");
    $processor = CSVAsyncProcessor::make()
        ->fromFile($path)
        ->withOptions(CSVImportOptionsData::from(['filePath' => $path]))
        ->withChunkSize(2)
        ->mapField('id', 'id', CSVFieldMapping::typed('id', 'id', CSVTypeEnum::INTEGER))
        ->processRow(static function (): void {})
        ->onProgress(static function (): void {})
        ->onBatchComplete(static function (): void {})
        ->onComplete(static function (): void {});

    $batch = $processor->processAsync();

    expect($batch->totalJobs)->toBe(2)
        ->and(Storage::disk('local')->allFiles('csv_processing_chunks'))->toHaveCount(2);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        expect($pending->jobs)->toHaveCount(2)
            ->and($pending->name)->toStartWith('CSV Processing:');

        foreach ($pending->jobs as $job) {
            expect($job)->toBeInstanceOf(ProcessCSVChunkJob::class)
                ->and($job->chunkData)->toBe([])
                ->and(serialize($job))->toBeString();
        }

        return true;
    });
});

it('tracks, reports, and cancels fake batches through the public API', function (): void {
    Bus::fake();
    $path = $this->temporaryCsv("id\n1\n");
    $processor = CSVAsyncProcessor::make()
        ->fromFile($path)
        ->withOptions(CSVImportOptionsData::from(['filePath' => $path]));

    expect($processor->getBatchStatus('missing'))->toBe(['status' => 'not_found'])
        ->and($processor->cancelBatch('missing'))->toBeFalse();

    $batchId = $processor->processAsyncWithTracking();
    $status = $processor->getBatchStatus($batchId);

    expect($status['id'])->toBe($batchId)
        ->and($status['status'])->toBe('processing')
        ->and($status['progress']['total_jobs'])->toBe(1)
        ->and($status['metadata'])->toMatchArray([
            'file_path' => $path,
            'chunk_size' => 1000,
            'status' => 'processing',
        ])
        ->and($processor->cancelBatch($batchId))->toBeTrue()
        ->and($processor->getBatchStatus($batchId)['status'])->toBe('cancelled');
});

it('validates asynchronous configuration before dispatch', function (): void {
    expect(fn () => CSVAsyncProcessor::make()->withChunkSize(0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => CSVAsyncProcessor::make()->processAsync())
        ->toThrow(RuntimeException::class, 'File path and options');
});
