<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\Enums\CSVDuplicateStrategyEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Enums\CSVErrorLevelEnum;
use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Exceptions\CSVConfigurationException;
use Nvl\Csv\Exceptions\CSVFileNotFoundException;
use Nvl\Csv\Exceptions\CSVParseException;
use Nvl\Csv\Exceptions\CSVValidationException;
use Nvl\Csv\Services\CSVImport;
use Nvl\Csv\ValueObjects\CSVConfiguration;
use Nvl\Csv\ValueObjects\CSVFieldMapping;

it('imports mapped and transformed rows through the fluent compatibility API', function (): void {
    $path = $this->temporaryCsv(
        "name,age,email\n\"Doe, Jane\",42,jane@example.com\nJohn,31,john@example.com\n",
    );
    $processed = [];

    $result = CSVImport::make()
        ->fromFile($path)
        ->mapField(
            'name',
            'full_name',
            CSVFieldMapping::withTransformer('name', 'full_name', fn (mixed $value): string => strtoupper((string) $value)),
        )
        ->mapField('age', 'age', CSVFieldMapping::typed('age', 'age', CSVTypeEnum::INTEGER, true))
        ->mapField('email', 'email')
        ->processRow(function (array $row, int $rowNumber) use (&$processed): array {
            $processed[$rowNumber] = $row;

            return [...$row, 'row_number' => $rowNumber];
        })
        ->import();

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->successfulRows)->toBe(2)
        ->and($result->failedRows)->toBe(0)
        ->and($processed[1]['full_name'])->toBe('DOE, JANE')
        ->and($processed[1]['age'])->toBe(42)
        ->and($result->metadata['file_path'])->toBe($path);
});

it('supports streaming, row limits, skipped rows, and reusable importer state', function (): void {
    $path = $this->temporaryCsv("id,name\n1,First\n,\n2,Second\n3,Third\n");
    $options = CSVImportOptionsData::from([
        'filePath' => $path,
        'skipRows' => 1,
        'limitRows' => 2,
        'skipEmptyRows' => true,
    ]);
    $import = CSVImport::make()->withOptions($options)->fromFile($path);

    $firstPass = iterator_to_array($import->stream());
    $secondPass = iterator_to_array($import->stream());

    expect(array_values($firstPass))->toBe([
        ['id' => '2', 'name' => 'Second'],
        ['id' => '3', 'name' => 'Third'],
    ])->and($secondPass)->toBe($firstPass)
        ->and($import->getProgress())->not->toBeNull();
});

it('supports headerless CSV files and normalizes uneven rows', function (): void {
    $path = $this->temporaryCsv("A,B\nC\nD,E,F\n");
    $rows = iterator_to_array(
        CSVImport::make()
            ->configure(new CSVConfiguration(includeHeaders: false))
            ->fromFile($path)
            ->stream(),
    );

    expect(array_values($rows))->toBe([
        ['col_0' => 'A', 'col_1' => 'B'],
        ['col_0' => 'C', 'col_1' => ''],
        ['col_0' => 'D', 'col_1' => 'E'],
    ]);
});

it('rejects uneven rows in strict mode while preserving lenient normalization', function (): void {
    $path = $this->temporaryCsv("id,name\n1,A\n2\n");

    $strictResult = CSVImport::make()
        ->configure(new CSVConfiguration(strictMode: true))
        ->fromFile($path)
        ->withTransaction(false)
        ->import();

    expect($strictResult->successfulRows)->toBe(1)
        ->and($strictResult->failedRows)->toBe(1)
        ->and($strictResult->errors[0])->toContain('Column count mismatch');
});

it('applies DTO column mappings, column types, and metadata', function (): void {
    $path = $this->temporaryCsv("external_id,email\n42,person@example.com\n");
    $processed = [];
    $options = CSVImportOptionsData::from([
        'filePath' => $path,
        'columnMapping' => ['external_id' => 'id'],
        'columnTypes' => ['external_id' => CSVTypeEnum::INTEGER],
        'metadata' => ['source' => 'partner-feed'],
    ]);

    $result = CSVImport::make()
        ->withOptions($options)
        ->processRow(function (array $row) use (&$processed): array {
            $processed[] = $row;

            return $row;
        })
        ->withTransaction(false)
        ->import();

    expect($processed)->toBe([['id' => 42]])
        ->and($result->metadata)->toMatchArray([
            'source' => 'partner-feed',
            'file_path' => $path,
        ]);
});

it('rejects non-string DTO column mapping keys', function (): void {
    $options = CSVImportOptionsData::from([
        'filePath' => '/tmp/source.csv',
        'columnMapping' => [0 => 'id'],
    ]);

    expect(fn () => CSVImport::make()->withOptions($options))
        ->toThrow(CSVConfigurationException::class, 'Column mapping keys must be non-empty strings.');
});

it('does not roll back a caller transaction when setup fails before its own transaction begins', function (): void {
    $path = $this->temporaryCsv('');
    DB::beginTransaction();

    try {
        expect(fn () => CSVImport::make()->fromFile($path)->import())
            ->toThrow(CSVParseException::class);
        expect(DB::transactionLevel())->toBe(1);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

it('honors the configured error threshold when stop on error is enabled', function (): void {
    $path = $this->temporaryCsv("id\n1\n2\n3\n");

    $result = CSVImport::make()
        ->fromFile($path)
        ->processRow(function (array $row): array {
            if ($row['id'] === '1') {
                throw new Exception('recoverable callback failure');
            }

            return $row;
        })
        ->stopOnError()
        ->withErrorThreshold(CSVErrorLevelEnum::ERROR)
        ->withTransaction(false)
        ->import();

    expect($result->failedRows)->toBe(1)
        ->and($result->successfulRows)->toBe(2);
});

it('processes synchronous batches transactionally and validates batch sizes', function (): void {
    $path = $this->temporaryCsv("id,name\n1,A\n2,B\n3,C\n");
    $batches = [];

    $result = CSVImport::make()
        ->fromFile($path)
        ->batch(2, function (array $rows, int $batchNumber) use (&$batches): void {
            $batches[$batchNumber] = $rows;
        });

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->successfulRows)->toBe(3)
        ->and($batches)->toHaveCount(2)
        ->and($batches[1])->toHaveCount(2)
        ->and($batches[2])->toHaveCount(1);

    expect(fn () => CSVImport::make()->fromFile($path)->batch(0, static function (): void {}))
        ->toThrow(InvalidArgumentException::class, 'at least 1');
});

it('applies error thresholds to batch callback failures', function (): void {
    $path = $this->temporaryCsv("id\n1\n2\n3\n");
    $attemptedBatches = [];

    $result = CSVImport::make()
        ->fromFile($path)
        ->stopOnError()
        ->withErrorThreshold(CSVErrorLevelEnum::CRITICAL)
        ->batch(1, function (array $rows, int $batchNumber) use (&$attemptedBatches): void {
            $attemptedBatches[] = $batchNumber;
            if ($batchNumber === 1) {
                throw new Exception('recoverable batch failure');
            }
        });

    expect($attemptedBatches)->toBe([1, 2, 3])
        ->and($result->successfulRows)->toBe(2)
        ->and($result->failedRows)->toBe(1);
});

it('applies skip, error, and pass-through duplicate strategies', function (): void {
    $path = $this->temporaryCsv("email,name\na@example.com,A\na@example.com,B\n");

    $skipped = CSVImport::make()
        ->fromFile($path)
        ->detectDuplicates('email')
        ->withDuplicateStrategy(CSVDuplicateStrategyEnum::SKIP)
        ->import();

    $errored = CSVImport::make()
        ->fromFile($path)
        ->detectDuplicates('email')
        ->withDuplicateStrategy(CSVDuplicateStrategyEnum::ERROR)
        ->import();

    foreach ([
        CSVDuplicateStrategyEnum::CREATE,
        CSVDuplicateStrategyEnum::UPDATE,
        CSVDuplicateStrategyEnum::REPLACE,
        CSVDuplicateStrategyEnum::MERGE,
        CSVDuplicateStrategyEnum::INCREMENT,
        CSVDuplicateStrategyEnum::ARCHIVE,
    ] as $strategy) {
        $result = CSVImport::make()
            ->fromFile($path)
            ->detectDuplicates('email')
            ->withDuplicateStrategy($strategy)
            ->import();

        expect($result->successfulRows)->toBe(2);
    }

    expect($skipped->successfulRows)->toBe(1)
        ->and($skipped->skippedRows)->toBe(1)
        ->and($errored->successfulRows)->toBe(1)
        ->and($errored->failedRows)->toBe(1)
        ->and($errored->errors[0])->toContain('Duplicate value');
});

it('uses unique field mappings and reports validation failures', function (): void {
    $path = $this->temporaryCsv("id,email\n1,invalid\n1,second@example.com\n");
    $mapping = new CSVFieldMapping(
        sourceField: 'id',
        targetField: 'identifier',
        type: CSVTypeEnum::INTEGER,
        required: true,
        unique: true,
        nullable: false,
    );

    $result = CSVImport::make()
        ->fromFile($path)
        ->mapFields([
            'id' => $mapping,
            'email' => CSVFieldMapping::typed('email', 'email', CSVTypeEnum::EMAIL),
        ])
        ->withDuplicateStrategy(CSVDuplicateStrategyEnum::SKIP)
        ->import();

    expect($result->successfulRows)->toBe(1)
        ->and($result->failedRows)->toBe(1)
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->failedRowsData)->toHaveCount(1);
});

it('reads non-local storage streams and UTF-16 input with a BOM', function (): void {
    Storage::fake('csv-imports');
    Storage::disk('csv-imports')->put('incoming/remote.csv', "id,name\n1,Remote\n");

    $remoteRows = iterator_to_array(
        CSVImport::make()->fromDisk('csv-imports', 'incoming/remote.csv')->stream(),
    );

    $utf16 = "\xFF\xFE".mb_convert_encoding("name,city\nИван,София\n", 'UTF-16LE', 'UTF-8');
    $utf16Path = $this->temporaryCsv($utf16);
    $utf16Rows = iterator_to_array(
        CSVImport::make()
            ->fromFile($utf16Path)
            ->withEncoding(CSVEncodingEnum::UTF16_LE)
            ->stream(),
    );

    expect(array_values($remoteRows))->toBe([['id' => '1', 'name' => 'Remote']])
        ->and(array_values($utf16Rows))->toBe([['name' => 'Иван', 'city' => 'София']]);
});

it('invokes error and progress callbacks without leaking state', function (): void {
    $rows = ["value\n"];
    for ($index = 0; $index < 100; $index++) {
        $rows[] = $index === 0 ? "invalid\n" : "{$index}\n";
    }

    $path = $this->temporaryCsv(implode('', $rows));
    $reportedErrors = [];
    $progress = [];

    $result = CSVImport::make()
        ->fromFile($path)
        ->mapField('value', 'value', CSVFieldMapping::typed('value', 'value', CSVTypeEnum::INTEGER, true))
        ->onError(function (array $row, Throwable $error, int $rowNumber) use (&$reportedErrors): void {
            $reportedErrors[] = [$row, $error->getMessage(), $rowNumber];
        })
        ->onProgress(function (array $snapshot) use (&$progress): void {
            $progress[] = $snapshot;
        })
        ->import();

    expect($result->failedRows)->toBe(1)
        ->and($result->successfulRows)->toBe(99)
        ->and($reportedErrors)->toHaveCount(1)
        ->and($progress)->toHaveCount(1);
});

it('bounds retained failure diagnostics without losing failure counts', function (): void {
    $rows = ["value\n"];
    for ($index = 0; $index < 1002; $index++) {
        $rows[] = "invalid-{$index}\n";
    }

    $result = CSVImport::make()
        ->fromFile($this->temporaryCsv(implode('', $rows)))
        ->mapField('value', 'value', CSVFieldMapping::typed('value', 'value', CSVTypeEnum::INTEGER, true))
        ->withTransaction(false)
        ->import();

    expect($result->failedRows)->toBe(1002)
        ->and($result->failedRowsData)->toHaveCount(1000)
        ->and($result->errors)->toHaveCount(1000)
        ->and($result->warnings)->toContain(
            'Additional CSV failure details were omitted after the retention limit was reached.',
        );
});

it('rejects missing files, empty inputs, and missing required headers', function (): void {
    expect(fn () => CSVImport::make()->fromFile('/definitely/missing.csv'))
        ->toThrow(CSVFileNotFoundException::class);

    $empty = $this->temporaryCsv('');
    expect(fn () => CSVImport::make()->fromFile($empty)->import())
        ->toThrow(CSVParseException::class);

    $duplicateHeaders = $this->temporaryCsv("id,id\n1,2\n");
    expect(fn () => CSVImport::make()->fromFile($duplicateHeaders)->import())
        ->toThrow(CSVParseException::class);

    $path = $this->temporaryCsv("name\nJane\n");
    expect(fn () => CSVImport::make()
        ->fromFile($path)
        ->mapField('email', 'email', CSVFieldMapping::typed('email', 'email', CSVTypeEnum::EMAIL, true))
        ->import())
        ->toThrow(CSVValidationException::class);
});
