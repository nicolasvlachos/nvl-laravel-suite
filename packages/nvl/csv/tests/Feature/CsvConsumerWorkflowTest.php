<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Services\CSVAnalyzerService;
use Nvl\Csv\Services\CSVExport;
use Nvl\Csv\Services\CSVImport;
use Nvl\Csv\ValueObjects\CSVFieldMapping;

it('supports a consumer analyze import export workflow through public APIs', function (): void {
    Storage::fake('consumer-exports');
    $sourcePath = $this->temporaryCsv("external_id;name\n42;Jane Doe\n43;John Doe\n");

    $sourceAnalysis = (new CSVAnalyzerService)->analyzeFile($sourcePath);
    $importedRows = [];
    $importOptions = CSVImportOptionsData::from([
        'filePath' => $sourcePath,
        'delimiter' => $sourceAnalysis->detectedDelimiter,
        'encoding' => $sourceAnalysis->detectedEncoding,
        'hasHeaders' => $sourceAnalysis->headers !== [],
        'chunkSize' => $sourceAnalysis->requiresChunking
            ? $sourceAnalysis->recommendedChunkSize
            : null,
        'skipEmptyRows' => $sourceAnalysis->emptyRowCount > 0,
        'strictMode' => false,
    ]);

    $import = CSVImport::make()
        ->withOptions($importOptions)
        ->mapField(
            'external_id',
            'id',
            CSVFieldMapping::typed('external_id', 'id', CSVTypeEnum::INTEGER, required: true),
        )
        ->mapField('name', 'display_name')
        ->processRow(function (array $row) use (&$importedRows): array {
            $importedRows[] = $row;

            return $row;
        })
        ->withTransaction(false)
        ->import();

    $export = CSVExport::make()
        ->disk('consumer-exports')
        ->path('reports')
        ->filename('people.csv')
        ->headings(['ID', 'Display Name'])
        ->fields(['id', 'display_name'])
        ->fromArray($importedRows);

    $exportAnalysis = (new CSVAnalyzerService)->analyzeFromDisk(
        'consumer-exports',
        'reports/people.csv',
    );

    expect($sourceAnalysis->detectedDelimiter)->toBe(CSVDelimiterEnum::SEMICOLON)
        ->and($import->isSuccessful())->toBeTrue()
        ->and($importedRows)->toBe([
            ['id' => 42, 'display_name' => 'Jane Doe'],
            ['id' => 43, 'display_name' => 'John Doe'],
        ])
        ->and($export->isSuccessful())->toBeTrue()
        ->and($export->rowCount)->toBe(2)
        ->and($exportAnalysis->headers)->toBe(['ID', 'Display Name'])
        ->and($exportAnalysis->rowCount)->toBe(2)
        ->and(Storage::disk('consumer-exports')->get('reports/people.csv'))
        ->toContain("42,\"Jane Doe\"\n");
});
