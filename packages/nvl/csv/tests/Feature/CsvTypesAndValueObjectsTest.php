<?php

declare(strict_types=1);

use Carbon\Carbon;
use Nvl\Csv\Data\CSVAnalysisResultData;
use Nvl\Csv\Data\CSVExportOptionsData;
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\Data\CSVProgressData;
use Nvl\Csv\Enums\CSVDataQualityEnum;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVDuplicateStrategyEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Enums\CSVErrorLevelEnum;
use Nvl\Csv\Enums\CSVExportFormatEnum;
use Nvl\Csv\Enums\CSVNotificationChannelEnum;
use Nvl\Csv\Enums\CSVOperationStatusEnum;
use Nvl\Csv\Enums\CSVProcessingModeEnum;
use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Exceptions\CSVConfigurationException;
use Nvl\Csv\Exceptions\CSVException;
use Nvl\Csv\Exceptions\CSVFileNotFoundException;
use Nvl\Csv\Exceptions\CSVMemoryException;
use Nvl\Csv\Exceptions\CSVParseException;
use Nvl\Csv\Exceptions\CSVValidationException;
use Nvl\Csv\ValueObjects\CSVConfiguration;
use Nvl\Csv\ValueObjects\CSVExportResult;
use Nvl\Csv\ValueObjects\CSVFieldMapping;
use Nvl\Csv\ValueObjects\CSVImportResult;

it('exposes every delimiter, format, encoding, and processing mode contract', function (): void {
    foreach (CSVDelimiterEnum::cases() as $delimiter) {
        expect($delimiter->getCharacter())->toBeString()
            ->and($delimiter->label())->toBeString()
            ->and($delimiter->getFileExtension())->toBeString()
            ->and(CSVDelimiterEnum::fromCharacter($delimiter->getCharacter()))->toBe($delimiter);
    }
    expect(CSVDelimiterEnum::fromCharacter('~'))->toBeNull();

    foreach (CSVExportFormatEnum::cases() as $format) {
        expect($format->getSettings())->toHaveKeys([
            'delimiter', 'enclosure', 'escape', 'line_ending', 'include_bom',
        ])->and($format->label())->toBeString()
            ->and($format->description())->toBeString()
            ->and($format->getFileExtension())->toBeString()
            ->and($format->includeBOM())->toBeBool()
            ->and($format->getDelimiter())->toBeString()
            ->and($format->getEnclosure())->toBeString()
            ->and($format->getEscape())->toBeString()
            ->and($format->getLineEnding())->toBeString();
    }

    foreach (CSVEncodingEnum::cases() as $encoding) {
        expect($encoding->getPhpEncoding())->toBeString()
            ->and($encoding->hasBOM())->toBeBool()
            ->and($encoding->getBOM())->toBeString()
            ->and($encoding->canConvert('UTF-8'))->toBeBool()
            ->and($encoding->getEncodingFamily())->toBeString()
            ->and($encoding->label())->toBeString();
    }
    expect(CSVEncodingEnum::detectFromBOM("\xEF\xBB\xBFdata"))->toBe(CSVEncodingEnum::UTF8_BOM)
        ->and(CSVEncodingEnum::detectFromBOM("\xFF\xFE\x00\x00data"))->toBe(CSVEncodingEnum::UTF32_LE)
        ->and(CSVEncodingEnum::detectFromBOM("\xFF\xFEdata"))->toBe(CSVEncodingEnum::UTF16_LE)
        ->and(CSVEncodingEnum::detectFromBOM("\xFE\xFFdata"))->toBe(CSVEncodingEnum::UTF16_BE)
        ->and(CSVEncodingEnum::detectFromBOM("\x00\x00\xFE\xFFdata"))->toBe(CSVEncodingEnum::UTF32)
        ->and(CSVEncodingEnum::detectFromBOM('plain'))->toBeNull()
        ->and(CSVEncodingEnum::excelRecommended())->toBe(CSVEncodingEnum::UTF8_BOM)
        ->and(CSVEncodingEnum::excelRecommended(false))->toBe(CSVEncodingEnum::UTF8);

    foreach (CSVProcessingModeEnum::cases() as $mode) {
        expect($mode->getDefaultChunkSize())->toBeInt()
            ->and($mode->supportsFiltering())->toBeBool()
            ->and($mode->supportsRandomAccess())->toBeBool()
            ->and($mode->getRecommendedMemoryLimit())->toBeInt()
            ->and($mode->isSuitableForLargeFiles())->toBeBool()
            ->and($mode->label())->toBeString()
            ->and($mode->description())->toBeString()
            ->and($mode->requiresQueue())->toBeBool()
            ->and($mode->getPerformanceCharacteristics())->toHaveKeys(['speed', 'memory', 'scalability']);
    }
});

it('exposes all quality, severity, duplicate, status, and notification metadata', function (): void {
    foreach (CSVDataQualityEnum::cases() as $quality) {
        expect($quality->getValidityThreshold())->toBeFloat()
            ->and($quality->getValidityRange())->toHaveCount(2)
            ->and($quality->requiresManualReview())->toBeBool()
            ->and($quality->canAutoProcess())->toBeBool()
            ->and($quality->getImportStrategy())->toBeString()
            ->and($quality->getScoreMultiplier())->toBeFloat()
            ->and($quality->getConfidenceLevel())->toBeString()
            ->and($quality->getColor())->toBeString()
            ->and($quality->getIcon())->toBeString()
            ->and($quality->label())->toBeString()
            ->and($quality->getDescription())->toBeString()
            ->and($quality->getRecommendedActions())->toBeArray();
    }
    expect(CSVDataQualityEnum::fromScore(100))->toBe(CSVDataQualityEnum::EXCELLENT)
        ->and(CSVDataQualityEnum::fromScore(85))->toBe(CSVDataQualityEnum::GOOD)
        ->and(CSVDataQualityEnum::fromScore(65))->toBe(CSVDataQualityEnum::FAIR)
        ->and(CSVDataQualityEnum::fromScore(45))->toBe(CSVDataQualityEnum::POOR)
        ->and(CSVDataQualityEnum::fromScore(5))->toBe(CSVDataQualityEnum::CRITICAL)
        ->and(CSVDataQualityEnum::fromColumnScores([]))->toBe(CSVDataQualityEnum::CRITICAL)
        ->and(CSVDataQualityEnum::fromColumnScores([100, 20]))->toBeInstanceOf(CSVDataQualityEnum::class)
        ->and(CSVDataQualityEnum::productionReady())->toHaveCount(2)
        ->and(CSVDataQualityEnum::requiresIntervention())->toHaveCount(2);

    foreach (CSVErrorLevelEnum::cases() as $level) {
        expect($level->shouldStopProcessing())->toBeBool()
            ->and($level->shouldSkipRow())->toBeBool()
            ->and($level->shouldLog())->toBeBool()
            ->and($level->shouldLog(true))->toBeBool()
            ->and($level->shouldNotify())->toBeBool()
            ->and($level->getNumericLevel())->toBeInt()
            ->and($level->isMoreSevereThan(CSVErrorLevelEnum::INFO))->toBeBool()
            ->and($level->meetsThreshold(CSVErrorLevelEnum::WARNING))->toBeBool()
            ->and($level->getLogLevel())->toBeString()
            ->and($level->getConsoleColor())->toBeString()
            ->and($level->getIcon())->toBeString()
            ->and($level->label())->toBeString()
            ->and($level->getImpactDescription())->toBeString();
    }
    expect(CSVErrorLevelEnum::fromNumeric(1))->toBe(CSVErrorLevelEnum::DEBUG)
        ->and(CSVErrorLevelEnum::fromNumeric(0))->toBeNull()
        ->and(CSVErrorLevelEnum::sortedBySeverity())->toHaveCount(5)
        ->and(CSVErrorLevelEnum::stoppingLevels())->toBe([CSVErrorLevelEnum::CRITICAL])
        ->and(CSVErrorLevelEnum::skippingLevels())->toHaveCount(2);

    foreach (CSVDuplicateStrategyEnum::cases() as $strategy) {
        expect($strategy->shouldCheckExisting())->toBeBool()
            ->and($strategy->modifiesExisting())->toBeBool()
            ->and($strategy->createsNew())->toBeBool()
            ->and($strategy->preservesExisting())->toBeBool()
            ->and($strategy->stopsProcessing())->toBeBool()
            ->and($strategy->getMergeStrategy())->toBeString()
            ->and($strategy->getSqlOperation())->toBeString()
            ->and($strategy->getPriority())->toBeInt()
            ->and($strategy->requiresUniqueIdentifier())->toBeBool()
            ->and($strategy->getPerformanceImpact())->toBeString()
            ->and($strategy->label())->toBeString()
            ->and($strategy->getDescription())->toBeString()
            ->and($strategy->getIcon())->toBeString()
            ->and($strategy->getAuditMessage())->toBeString();
    }
    expect(CSVDuplicateStrategyEnum::upsertStrategies())->not->toBeEmpty()
        ->and(CSVDuplicateStrategyEnum::historyPreserving())->not->toBeEmpty()
        ->and(CSVDuplicateStrategyEnum::productionSafe())->not->toBeEmpty();

    foreach (CSVOperationStatusEnum::cases() as $status) {
        foreach (CSVOperationStatusEnum::cases() as $target) {
            expect($status->canTransitionTo($target))->toBeBool();
        }

        expect($status->isActive())->toBeBool()
            ->and($status->isTerminal())->toBeBool()
            ->and($status->canPause())->toBeBool()
            ->and($status->canResume())->toBeBool()
            ->and($status->canCancel())->toBeBool()
            ->and($status->canRetry())->toBeBool()
            ->and($status->isSuccess())->toBeBool()
            ->and($status->isFailure())->toBeBool()
            ->and($status->getValidTransitions())->toBeArray()
            ->and($status->getProgressRange())->toHaveCount(2)
            ->and($status->getColor())->toBeString()
            ->and($status->getIcon())->toBeString()
            ->and($status->label())->toBeString()
            ->and($status->getDescription())->toBeString()
            ->and($status->getPriority())->toBeInt();
    }
    expect(CSVOperationStatusEnum::inProgress())->not->toBeEmpty()
        ->and(CSVOperationStatusEnum::terminal())->toHaveCount(3);

    foreach (CSVNotificationChannelEnum::cases() as $channel) {
        expect($channel->requiresConfiguration())->toBeBool()
            ->and($channel->supportsRichContent())->toBeBool()
            ->and($channel->supportsAttachments())->toBeBool()
            ->and($channel->isRealTime())->toBeBool()
            ->and($channel->getPriority())->toBeInt()
            ->and($channel->getReliability())->toBeString()
            ->and($channel->getDeliverySpeed())->toBeString()
            ->and($channel->getCostTier())->toBeString()
            ->and($channel->getIcon())->toBeString()
            ->and($channel->label())->toBeString()
            ->and($channel->getDescription())->toBeString()
            ->and($channel->getRequiredConfig())->toBeArray()
            ->and($channel->getSuitableNotificationTypes())->toBeArray();
    }
    expect(CSVNotificationChannelEnum::errorChannels())->not->toBeEmpty()
        ->and(CSVNotificationChannelEnum::progressChannels())->not->toBeEmpty()
        ->and(CSVNotificationChannelEnum::reportChannels())->not->toBeEmpty();
});

it('casts and validates every CSV field type safely', function (): void {
    expect(CSVTypeEnum::STRING->cast(42))->toBe('42')
        ->and(CSVTypeEnum::INTEGER->cast('42'))->toBe(42)
        ->and(CSVTypeEnum::FLOAT->cast('4.2'))->toBe(4.2)
        ->and(CSVTypeEnum::BOOLEAN->cast('yes'))->toBeTrue()
        ->and(CSVTypeEnum::BOOLEAN->cast('no'))->toBeFalse()
        ->and(CSVTypeEnum::DATE->cast('2026-01-02'))->toBe('2026-01-02')
        ->and(CSVTypeEnum::DATETIME->cast('2026-01-02 03:04:05'))->toBe('2026-01-02 03:04:05')
        ->and(CSVTypeEnum::EMAIL->cast('person@example.com'))->toBe('person@example.com')
        ->and(CSVTypeEnum::JSON->cast(['id' => 1]))->toBe('{"id":1}')
        ->and(CSVTypeEnum::ARRAY->cast('["a","b"]'))->toBe(['a', 'b'])
        ->and(CSVTypeEnum::ARRAY->cast('a,b'))->toBe(['a', 'b'])
        ->and(CSVTypeEnum::ARRAY->cast(1))->toBe([1])
        ->and(CSVTypeEnum::NULLABLE_STRING->cast(''))->toBeNull()
        ->and(CSVTypeEnum::NULLABLE_INTEGER->cast(null))->toBeNull()
        ->and(CSVTypeEnum::NULLABLE_FLOAT->cast(''))->toBeNull();

    foreach (CSVTypeEnum::cases() as $type) {
        expect($type->getPhpType())->toBeString()
            ->and($type->isNullable())->toBeBool()
            ->and($type->getDefaultValue())->toBeIn([null, '', 0, 0.0, false, '{}', []])
            ->and($type->validate($type->getDefaultValue()))->toBeBool();
    }

    expect(CSVTypeEnum::INTEGER->validate('4.2'))->toBeFalse()
        ->and(CSVTypeEnum::BOOLEAN->validate(2))->toBeFalse()
        ->and(CSVTypeEnum::BOOLEAN->validate('perhaps'))->toBeFalse()
        ->and(CSVTypeEnum::EMAIL->validate('bad'))->toBeFalse()
        ->and(CSVTypeEnum::JSON->validate(['id' => 1]))->toBeTrue()
        ->and(CSVTypeEnum::JSON->validate('{bad'))->toBeFalse()
        ->and(CSVTypeEnum::DATE->validate('2026-02-31'))->toBeFalse()
        ->and(CSVTypeEnum::DATE->validate([]))->toBeFalse();

    expect(fn () => CSVTypeEnum::INTEGER->cast('4.2'))->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVTypeEnum::FLOAT->cast([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVTypeEnum::BOOLEAN->cast(2))->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVTypeEnum::JSON->cast('{bad'))->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVTypeEnum::BOOLEAN->cast('perhaps'))->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVTypeEnum::EMAIL->cast('bad'))->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVTypeEnum::STRING->cast([]))->toThrow(InvalidArgumentException::class);
});

it('builds immutable configurations and typed option DTOs', function (): void {
    $configuration = CSVConfiguration::fromFormat(CSVExportFormatEnum::EXCEL)
        ->withDelimiter(';')
        ->withProcessingMode(CSVProcessingModeEnum::STREAM)
        ->withChunkSize(250)
        ->withIncludeIndex();

    expect($configuration->delimiter)->toBe(';')
        ->and($configuration->includeIndex)->toBeTrue()
        ->and($configuration->isStreaming())->toBeTrue()
        ->and($configuration->isChunked())->toBeTrue()
        ->and($configuration->getEffectiveChunkSize())->toBe(250)
        ->and($configuration->toArray()['processing_mode'])->toBe('stream')
        ->and(CSVConfiguration::fromDelimiter(CSVDelimiterEnum::TAB)->delimiter)->toBe("\t")
        ->and(CSVConfiguration::default()->isChunked())->toBeFalse()
        ->and(CSVConfiguration::excel()->includeBOM)->toBeTrue()
        ->and(CSVConfiguration::largeFile()->isStreaming())->toBeTrue();

    $export = CSVExportOptionsData::excel('report.csv');
    $largeExport = CSVExportOptionsData::largeFile('large.csv', 500);
    $import = CSVImportOptionsData::strict('/tmp/import.csv');
    $largeImport = CSVImportOptionsData::largeFile('/tmp/large.csv', 500);

    expect($export->getFullPath())->toBe('report.csv')
        ->and($export->getDelimiterCharacter())->toBe(',')
        ->and($export->getEncoding())->toBe(CSVEncodingEnum::UTF8)
        ->and($largeExport->isChunked())->toBeTrue()
        ->and($largeExport->getFullPath())->toBe('large.csv')
        ->and($import->shouldValidate())->toBeTrue()
        ->and($import->hasHeaderRow())->toBeTrue()
        ->and($import->getSkipRows())->toBe(0)
        ->and($import->getLimitRows())->toBeNull()
        ->and($import->getDuplicateStrategy())->toBe(CSVDuplicateStrategyEnum::SKIP)
        ->and($import->getEncoding())->toBe(CSVEncodingEnum::UTF8)
        ->and($import->getDelimiterCharacter())->toBe(',')
        ->and($largeImport->isChunked())->toBeTrue()
        ->and(CSVExportOptionsData::rules())->toHaveKey('filename')
        ->and(CSVImportOptionsData::rules())->toHaveKey('filePath')
        ->and(CSVExportOptionsData::messages())->toBeArray()
        ->and(CSVImportOptionsData::attributes())->toBeArray();
});

it('tracks operation progress and result statistics', function (): void {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $progress = CSVProgressData::initial('operation-1', 100);
    Carbon::setTestNow('2026-01-01 00:00:10');
    $progress = $progress->update([
        'processedRows' => 50,
        'successfulRows' => 45,
        'failedRows' => 3,
        'skippedRows' => 2,
        'status' => CSVOperationStatusEnum::RUNNING,
    ]);

    expect($progress->isRunning())->toBeTrue()
        ->and($progress->percentComplete)->toBe(50.0)
        ->and($progress->rowsPerSecond)->toBe(5.0)
        ->and($progress->getTimeRemainingFormatted())->toBe('10 sec')
        ->and($progress->getDuration())->toBe(10.0)
        ->and($progress->getMemoryUsageFormatted())->toBeString()
        ->and($progress->getProgressBarData()['current'])->toBe(50)
        ->and($progress->getStatistics()['success_rate'])->toBe('90%');

    $completed = $progress->complete();
    $failed = $progress->fail('Stopped', CSVErrorLevelEnum::CRITICAL);

    expect($completed->isFinished())->toBeTrue()
        ->and($completed->isSuccessful())->toBeTrue()
        ->and($completed->getTimeRemainingFormatted())->toBe('0 sec')
        ->and($failed->isFinished())->toBeTrue()
        ->and($failed->isSuccessful())->toBeFalse()
        ->and(CSVProgressData::rules())->toHaveKey('operationId')
        ->and(CSVProgressData::messages())->toBeArray();

    Carbon::setTestNow();
});

it('summarizes import, export, and analysis value objects', function (): void {
    $started = Carbon::parse('2026-01-01 00:00:00');
    $completed = Carbon::parse('2026-01-01 00:00:10');
    $import = CSVImportResult::completed([
        'total_rows' => 10,
        'processed_rows' => 9,
        'successful_rows' => 7,
        'failed_rows' => 2,
        'skipped_rows' => 1,
        'started_at' => $started,
        'completed_at' => $completed,
        'errors' => ['two failures'],
        'warnings' => ['one warning'],
        'failed_rows_data' => [['row' => 2]],
        'metadata' => ['source' => 'fixture'],
    ]);
    $export = CSVExportResult::fromExport(
        ['path' => '/tmp/report.csv', 'url' => null],
        [
            'row_count' => 10,
            'column_count' => 2,
            'file_size' => 2048,
            'processing_time' => 2.0,
            'warnings' => ['warning'],
        ],
    );
    $analysis = CSVAnalysisResultData::from([
        'filePath' => '/tmp/data.csv',
        'fileSize' => 1024,
        'rowCount' => 100,
        'columnCount' => 2,
        'headers' => ['id', 'name'],
        'detectedDelimiter' => CSVDelimiterEnum::COMMA,
        'detectedEncoding' => CSVEncodingEnum::UTF8,
        'hasBom' => false,
        'lineEnding' => 'LF',
        'dataQuality' => CSVDataQualityEnum::GOOD,
        'validityScore' => 90,
        'emptyRowCount' => 1,
        'inconsistentRowCount' => 0,
        'columnAnalysis' => [
            'id' => ['type' => 'numeric', 'nullable' => false, 'sample' => '1'],
            'name' => ['type' => 'text', 'nullable' => false, 'sample' => 'Jane'],
        ],
        'duplicateAnalysis' => ['id' => 0, 'name' => 1],
        'numericStatistics' => [],
        'textStatistics' => [],
        'dateFormatAnalysis' => [],
        'issues' => [['severity' => 'critical', 'message' => 'Review']],
        'recommendations' => ['Review data'],
        'estimatedMemoryUsage' => 1024,
        'requiresChunking' => false,
        'recommendedChunkSize' => 0,
        'analyzedAt' => Carbon::now(),
        'analysisTime' => 0.1,
    ]);

    expect($import->isPartiallySuccessful())->toBeTrue()
        ->and($import->hasErrors())->toBeTrue()
        ->and($import->hasWarnings())->toBeTrue()
        ->and($import->getSuccessRate())->toBe(77.78)
        ->and($import->getFailureRate())->toBe(22.22)
        ->and($import->getSkipRate())->toBe(10.0)
        ->and($import->getRowsPerSecond())->toBe(0.9)
        ->and($import->getSummary())->toContain('7 rows')
        ->and($import->getStatistics())->toHaveKey('success_rate')
        ->and($import->toArray()['successful'])->toBeFalse()
        ->and(CSVImportResult::initial(0)->isSuccessful())->toBeTrue()
        ->and(CSVImportResult::initial(1)->isCompleteFailure())->toBeFalse()
        ->and($export->isSuccessful())->toBeTrue()
        ->and($export->hasWarnings())->toBeTrue()
        ->and($export->hasErrors())->toBeFalse()
        ->and($export->getHumanFileSize())->toBe('2 KB')
        ->and($export->getProcessingTimeInSeconds())->toBe(2.0)
        ->and($export->getRowsPerSecond())->toBe(5.0)
        ->and($analysis->getFileSizeFormatted())->toBe('1024 B')
        ->and($analysis->getCriticalIssues())->toHaveCount(1)
        ->and($analysis->getProcessingStrategy())->toBe('memory')
        ->and($analysis->getMemoryRecommendation())->toBeString()
        ->and(CSVAnalysisResultData::rules())->toHaveKey('validityScore')
        ->and(CSVAnalysisResultData::attributes())->toBeArray();
});

it('preserves field mapping factories, serialization, validation, and metadata', function (): void {
    $simple = CSVFieldMapping::simple('name', 'display_name');
    $typed = CSVFieldMapping::typed('age', 'age', CSVTypeEnum::INTEGER, true);
    $transformed = new CSVFieldMapping(
        sourceField: 'name',
        targetField: 'name',
        defaultValue: 'Unknown',
        transformer: fn (mixed $value): string => strtoupper((string) $value),
        validators: [fn (mixed $value): bool => $value !== 'forbidden'],
        unique: true,
        metadata: ['label' => 'Name'],
    );
    $restored = unserialize(serialize($transformed));

    expect($simple->apply('Jane'))->toBe('Jane')
        ->and($typed->validate('42'))->toBeTrue()
        ->and($typed->apply('42'))->toBe(42)
        ->and($typed->validate(''))->toBeFalse()
        ->and($typed->getValidationErrors(''))->not->toBeEmpty()
        ->and($restored)->toBeInstanceOf(CSVFieldMapping::class)
        ->and($restored->apply('jane'))->toBe('JANE')
        ->and($restored->validate('forbidden'))->toBeFalse()
        ->and($restored->apply(''))->toBe('Unknown')
        ->and($restored->shouldIndex())->toBeTrue()
        ->and($restored->toArray()['metadata'])->toBe(['label' => 'Name']);
});

it('builds contextual exceptions for every compatibility factory', function (): void {
    $base = (new CSVConfigurationException('Failure'))->withContext(['row' => 2]);
    $emptyFile = $this->temporaryCsv('');
    expect($base->getContext())->toBe(['row' => 2])
        ->and($base->getContextValue('row'))->toBe(2)
        ->and($base->getContextValue('missing', 'fallback'))->toBe('fallback')
        ->and($base->toArray())->toMatchArray(['message' => 'Failure', 'context' => ['row' => 2]]);

    $exceptions = [
        CSVConfigurationException::invalidDelimiter('~'),
        CSVConfigurationException::invalidEnclosure('xx'),
        CSVConfigurationException::invalidChunkSize(0),
        CSVConfigurationException::invalidProcessingMode('bad'),
        CSVConfigurationException::missingConfiguration('file'),
        CSVConfigurationException::invalidDisk('disk'),
        CSVConfigurationException::invalidMemoryLimit(0),
        CSVFileNotFoundException::fileNotFound('/tmp/a.csv'),
        CSVFileNotFoundException::fileNotFoundOnDisk('s3', 'a.csv'),
        CSVFileNotFoundException::directoryNotFound('/tmp/nope'),
        CSVFileNotFoundException::fileNotReadable('/tmp/nope'),
        CSVFileNotFoundException::pathNotWritable('/tmp/nope'),
        CSVFileNotFoundException::fileEmpty($emptyFile),
        CSVMemoryException::memoryLimitExceeded(100, 50),
        CSVMemoryException::fileTooLarge('a.csv', 100, 50),
        CSVMemoryException::insufficientMemory(100, 50),
        CSVMemoryException::chunkSizeTooLarge(100, 10),
        CSVMemoryException::allocationFailed('buffer'),
        CSVParseException::invalidStructure('bad', 2),
        CSVParseException::headerMismatch(['id'], ['name']),
        CSVParseException::columnCountMismatch(2, 1, 3),
        CSVParseException::invalidEncoding('UTF-8', 2),
        CSVParseException::missingRequiredColumn('id'),
        CSVParseException::cannotDetectDelimiter(),
        CSVParseException::parsingFailed('bad', 2),
        CSVParseException::invalidHeaders(),
        CSVValidationException::fieldValidationFailed('id', [], 'integer'),
        CSVValidationException::invalidType('id', [], 'integer'),
        CSVValidationException::requiredFieldMissing('id', 2),
        CSVValidationException::duplicateValue('id', 1, 2),
        CSVValidationException::missingRequiredFields(['id']),
    ];

    foreach ($exceptions as $exception) {
        expect($exception)->toBeInstanceOf(CSVException::class)
            ->and($exception->getMessage())->not->toBeEmpty();
    }

    $validation = CSVValidationException::validationFailed([
        2 => ['email' => ['Invalid']],
        4 => ['name' => ['Required']],
    ]);
    $rowValidation = CSVValidationException::rowValidationFailed(3, ['email' => ['Invalid']]);

    expect($validation->getErrorCount())->toBe(2)
        ->and($validation->getAffectedRows())->toBe([2, 4])
        ->and($validation->getRowErrors(2))->toBe(['email' => ['Invalid']])
        ->and($validation->getRowErrors(99))->toBeNull()
        ->and($validation->getValidationErrors())->toHaveCount(2)
        ->and($rowValidation->getAffectedRows())->toBe([3]);
});
