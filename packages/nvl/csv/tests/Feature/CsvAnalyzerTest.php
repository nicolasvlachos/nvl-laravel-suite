<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Exceptions\CSVFileNotFoundException;
use Nvl\Csv\Services\CSVAnalyzerService;

it('detects dialect, types, duplicates, quality issues, and recommendations', function (): void {
    $path = $this->temporaryCsv(
        "id;name;amount;active;created_at\r\n".
        "1;\"Doe, Jane\";10.50;yes;2026-01-01\r\n".
        "2;John;20;no;2026-01-02\r\n".
        "2;John;30;yes;2026-01-03\r\n".
        ";;;;\r\n".
        "4;Only two\r\n",
    );

    $result = (new CSVAnalyzerService)->analyzeFile($path);

    expect($result->detectedDelimiter)->toBe(CSVDelimiterEnum::SEMICOLON)
        ->and($result->detectedEncoding)->toBe(CSVEncodingEnum::UTF8)
        ->and($result->lineEnding)->toBe('CRLF')
        ->and($result->rowCount)->toBe(5)
        ->and($result->columnCount)->toBe(5)
        ->and($result->emptyRowCount)->toBe(1)
        ->and($result->inconsistentRowCount)->toBe(1)
        ->and($result->columnAnalysis['amount']['type'])->toBe('numeric')
        ->and($result->columnAnalysis['active']['type'])->toBe('boolean')
        ->and($result->columnAnalysis['created_at']['type'])->toBe('date')
        ->and($result->hasDuplicates())->toBeTrue()
        ->and($result->hasIssues())->toBeTrue()
        ->and($result->getWarnings())->not->toBeEmpty()
        ->and($result->getColumnTypesSummary()['numeric'])->toBeGreaterThanOrEqual(1)
        ->and($result->getSuggestedImportConfig()['delimiter'])->toBe('semicolon')
        ->and($result->toSummary()['columns'])->toBe(5);
});

it('resets analyzer state between full and quick analyses', function (): void {
    $first = $this->temporaryCsv("id,name\n1,A\n2,B\n");
    $second = $this->temporaryCsv("code|value\nx|10\n");
    $analyzer = new CSVAnalyzerService;

    $firstResult = $analyzer->analyzeFile($first);
    $secondResult = $analyzer->analyzeFile($second);
    $quick = $analyzer->quickAnalyze($first);

    expect($firstResult->rowCount)->toBe(2)
        ->and($secondResult->rowCount)->toBe(1)
        ->and($secondResult->headers)->toBe(['code', 'value'])
        ->and($secondResult->detectedDelimiter)->toBe(CSVDelimiterEnum::PIPE)
        ->and($quick['sample_rows'])->toBe(2)
        ->and($quick['headers'])->toBe(['id', 'name']);
});

it('analyzes storage streams without requiring a local disk path', function (): void {
    Storage::fake('analysis');
    Storage::disk('analysis')->put('incoming/data.csv', "id\tname\n1\tRemote\n");

    $result = (new CSVAnalyzerService)->analyzeFromDisk('analysis', 'incoming/data.csv');

    expect($result->filePath)->toBe('analysis://incoming/data.csv')
        ->and($result->detectedDelimiter)->toBe(CSVDelimiterEnum::TAB)
        ->and($result->rowCount)->toBe(1)
        ->and($result->isValid())->toBeTrue()
        ->and($result->isConsistent())->toBeTrue();
});

it('detects and decodes variable-length Unicode BOMs', function (): void {
    $utf8Path = $this->temporaryCsv("\xEF\xBB\xBFid,name\n1,UTF8\n");
    $utf16Path = $this->temporaryCsv(
        "\xFF\xFE".mb_convert_encoding("id,name\n1,UTF16\n", 'UTF-16LE', 'UTF-8'),
    );

    $utf8 = (new CSVAnalyzerService)->analyzeFile($utf8Path);
    $utf16 = (new CSVAnalyzerService)->analyzeFile($utf16Path);

    expect($utf8->hasBom)->toBeTrue()
        ->and($utf8->detectedEncoding)->toBe(CSVEncodingEnum::UTF8_BOM)
        ->and($utf8->headers)->toBe(['id', 'name'])
        ->and($utf16->hasBom)->toBeTrue()
        ->and($utf16->detectedEncoding)->toBe(CSVEncodingEnum::UTF16_LE)
        ->and($utf16->headers)->toBe(['id', 'name'])
        ->and($utf16->sampleData)->not->toBeEmpty();
});

it('estimates large inputs and recommends chunked processing', function (): void {
    $contents = "id,value\n";
    for ($index = 1; $index <= 10001; $index++) {
        $contents .= "{$index},value-{$index}\n";
    }

    $result = (new CSVAnalyzerService)->analyzeFile($this->temporaryCsv($contents));

    expect($result->rowCount)->toBeGreaterThanOrEqual(10000)
        ->and($result->requiresChunking)->toBeTrue()
        ->and($result->recommendedChunkSize)->toBeGreaterThanOrEqual(100)
        ->and($result->getProcessingStrategy())->toBeIn(['chunked', 'streamed'])
        ->and($result->getEstimatedProcessingTime())->toBeGreaterThanOrEqual(0.0);
});

it('scores large files from analyzed rows instead of diluting sampled issues', function (): void {
    $rows = ["id,name\n", "1\n"];
    for ($index = 2; $index <= 10001; $index++) {
        $rows[] = "{$index},Name {$index}\n";
    }

    $result = (new CSVAnalyzerService)->analyzeFile($this->temporaryCsv(implode('', $rows)));

    expect($result->rowCount)->toBeGreaterThanOrEqual(10000)
        ->and($result->inconsistentRowCount)->toBe(1)
        ->and($result->validityScore)->toBe(99.99);
});

it('classifies binary boolean columns as boolean rather than numeric', function (): void {
    $result = (new CSVAnalyzerService)->analyzeFile(
        $this->temporaryCsv("id,active\n1,1\n2,0\n3,1\n"),
    );

    expect($result->columnAnalysis['active']['type'])->toBe('boolean');
});

it('detects delimiters across quoted fields containing embedded newlines', function (): void {
    $result = (new CSVAnalyzerService)->analyzeFile(
        $this->temporaryCsv("id;description\n1;\"first line\nsecond line\"\n2;plain\n"),
    );

    expect($result->detectedDelimiter)->toBe(CSVDelimiterEnum::SEMICOLON)
        ->and($result->rowCount)->toBe(2);
});

it('reports empty files and missing sources accurately', function (): void {
    expect(fn () => (new CSVAnalyzerService)->analyzeFile('/definitely/missing.csv'))
        ->toThrow(CSVFileNotFoundException::class);

    Storage::fake('missing-analysis');
    expect(fn () => (new CSVAnalyzerService)->analyzeFromDisk('missing-analysis', 'missing.csv'))
        ->toThrow(CSVFileNotFoundException::class);

    $result = (new CSVAnalyzerService)->analyzeFile($this->temporaryCsv(''));

    expect($result->isValid())->toBeFalse()
        ->and($result->getCriticalIssues())->not->toBeEmpty()
        ->and($result->getMemoryRecommendation())->toBe('Standard memory allocation sufficient');
});
