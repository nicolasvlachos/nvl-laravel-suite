# NVL CSV — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^1.0` |
| Module identifier | `nvl/csv` |
| PHP namespace | `Nvl\Csv` |
| Service provider | `Nvl\Csv\Providers\CsvServiceProvider` |
| Configuration | None; behavior is supplied through typed options and services |

Typed, memory-conscious CSV analysis, validation, transformation, import, export, and queued chunk processing for Laravel 12 and 13.

## Purpose

`nvl/csv` turns CSV handling into an explicit application boundary. It provides immutable dialect configuration, Spatie Data option and result objects, field mappings, reusable validators and transformers, streaming filesystem support, synchronous batches, and Laravel queue batches. The package is headless: it owns no routes, controllers, models, tables, or application-specific persistence.

The public namespace is `Nvl\Csv`. Its fluent import/export surface is compatible with the original `App\Lib\CSV` library while correcting its operational weaknesses: options are applied consistently, analyzers can be reused safely, BOM lengths and declared encodings are honored, remote Laravel disks are read as streams, exports are written as streams, duplicate policy is explicit, callback-bearing jobs serialize safely, and queued source chunks are staged instead of embedding the complete file in the queue payload.

## Requirements and installation

- PHP 8.3 or newer
- Laravel 12 or 13
- `ext-filter`, `ext-iconv`, `ext-json`, and `ext-mbstring`
- `nvl/data:^1.0`

Install with Composer:

```bash
composer require nvl/laravel-suite:^1.0
```

Laravel discovers `Nvl\Csv\Providers\CsvServiceProvider` automatically. There is no package configuration or migration to publish for synchronous analysis, import, or export.

Queued processing uses Laravel job batching. The consuming application must configure a queue connection and create the batch repository table if it does not already exist:

```bash
php artisan make:queue-batches-table
php artisan migrate
```

Keep the queue connection’s `retry_after` value above the job timeout of 300 seconds. Async jobs use the `csv-processing` queue and stage bounded JSON chunks on the application’s `local` filesystem disk until the job completes, is cancelled, or exhausts its retries.

## Analyze a file

```php
use Nvl\Csv\Services\CSVAnalyzerService;

$analysis = (new CSVAnalyzerService())->analyzeFile($absolutePath);

$analysis->detectedDelimiter;
$analysis->detectedEncoding;
$analysis->headers;
$analysis->columnAnalysis;
$analysis->issues;
$analysis->recommendations;
```

Use `analyzeFromDisk($disk, $path)` for a Laravel filesystem disk or `quickAnalyze($absolutePath)` for a small structural preview. Full analysis samples at most 100 rows for type/statistical inference and scans at most 10,000 data rows before estimating the total. The analyzer assumes the first parsed row is a header row.

Encoding and delimiter detection are recommendations, not proof. Ambiguous legacy encodings and CSV dialects should be confirmed by the caller before an irreversible import.

## Import

```php
use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Services\CSVImport;
use Nvl\Csv\ValueObjects\CSVFieldMapping;

$result = CSVImport::make()
    ->fromDisk('imports', 'incoming/people.csv')
    ->mapField(
        'age',
        'age',
        CSVFieldMapping::typed('age', 'age', CSVTypeEnum::INTEGER, required: true),
    )
    ->mapField(
        'name',
        'display_name',
        CSVFieldMapping::withTransformer(
            'name',
            'display_name',
            static fn (mixed $value): string => trim((string) $value),
        ),
    )
    ->processRow(static function (array $row, int $rowNumber): void {
        // Call an application Action or perform the intended persistence write.
    })
    ->import();
```

`fromFile()` accepts an absolute local path. `fromDisk()` reads a Laravel disk stream and does not require the adapter to expose a local path. With no field mappings, rows are returned as header-keyed associative arrays.

`import()` returns `CSVImportResult`. Imports use a database transaction by default. A result containing row failures causes that top-level transaction to roll back; use `withTransaction(false)` when successful rows should be committed independently, or use `stopOnError()` when the first threshold-matching error must stop processing.

For pull-based processing:

```php
foreach (CSVImport::make()->fromFile($path)->stream() as $rowNumber => $row) {
    // Streaming does not create an enclosing database transaction.
}
```

For bounded synchronous writes:

```php
$result = CSVImport::make()
    ->fromFile($path)
    ->batch(500, static function (array $rows, int $batchNumber): void {
        // Each callback is wrapped in its own transaction by default.
    });
```

Configure `CSVImportOptionsData` when the options cross an HTTP, command, or job boundary:

```php
use Nvl\Csv\Data\CSVImportOptionsData;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Enums\CSVTypeEnum;

$options = CSVImportOptionsData::from([
    'filePath' => $path,
    'delimiter' => CSVDelimiterEnum::SEMICOLON,
    'encoding' => CSVEncodingEnum::WINDOWS_1252,
    'skipRows' => 2,
    'limitRows' => 10_000,
    'hasHeaders' => true,
    'skipEmptyRows' => true,
    'strictMode' => true,
    'columnMapping' => ['external_id' => 'id'],
    'columnTypes' => ['external_id' => CSVTypeEnum::INTEGER],
    'metadata' => ['source' => 'partner-feed'],
]);

$result = CSVImport::make()
    ->withOptions($options)
    ->fromFile($path)
    ->import();
```

`columnMapping` accepts source-to-target field names or `CSVFieldMapping` objects. `columnTypes` accepts `CSVTypeEnum` instances or enum values and augments those mappings with validation and casting. Result metadata includes caller-provided DTO metadata plus operational fields.

`CSVConfiguration(includeHeaders: false)` produces `col_0`, `col_1`, and subsequent generated names for headerless input. Header names must be non-empty and unique. In lenient mode, short rows are padded and long rows are truncated to the known column count. Strict mode records a `CSVParseException` for uneven rows instead. Failed-row payloads and error strings are retained for the first 1,000 failures; counters remain exact and the result contains a warning when further diagnostic details are omitted.

## Duplicate handling

Call `detectDuplicates($field)` or set `unique: true` on a `CSVFieldMapping` to compare transformed values within the current source file.

- `SKIP` omits subsequent duplicate rows and increments `skippedRows`.
- `ERROR` records a validation failure for the duplicate row.
- `CREATE` accepts every row.
- `UPDATE`, `REPLACE`, `MERGE`, `INCREMENT`, and `ARCHIVE` pass the duplicate row to the application row processor. Their persistence meaning belongs to the consumer because the package does not know the target model, lookup key, archive schema, or write Action.

This duplicate index is operation-local and strict-type-sensitive. It does not replace a database unique constraint or concurrency-safe upsert.

## Export

```php
use Nvl\Csv\Services\CSVExport;
use Nvl\Csv\ValueObjects\CSVConfiguration;

$result = CSVExport::make()
    ->configure(CSVConfiguration::excel()->withIncludeIndex())
    ->disk('exports')
    ->path('reports')
    ->filename('people.csv')
    ->headings(['Name', 'Email'])
    ->fields(['name', 'email'])
    ->fromQuery($peopleQuery);
```

Export sources are:

- `fromArray(array $rows)`
- `fromCollection(Collection $rows)`
- `fromQuery(Builder $query)`, which always reads in bounded chunks
- `stream(Closure $provider)`, where the provider receives a writer callback

Fields may be dot-notated array keys or closures receiving the complete row. When fields and headings are omitted, the first row’s keys define both. Closure-based fields require explicit headings. Arrays and ordinary objects are JSON encoded, `DateTimeInterface` values use ISO 8601, backed enums use their values, and `Stringable` objects use their string representation. Booleans become `1` or `0`, and null becomes an empty field.

`CSVExportOptionsData` applies format, delimiter, enclosure, escape, BOM, headers, index, processing mode, chunk size, encoding, and memory settings:

```php
use Nvl\Csv\Data\CSVExportOptionsData;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Enums\CSVExportFormatEnum;

$options = CSVExportOptionsData::from([
    'disk' => 'exports',
    'path' => 'reports',
    'filename' => 'people.csv',
    'format' => CSVExportFormatEnum::RFC4180,
    'encoding' => CSVEncodingEnum::UTF8_BOM,
    'headings' => ['Name', 'Email'],
    'fields' => ['name', 'email'],
]);

$result = CSVExport::make()->withOptions($options)->fromArray($rows);
```

Fluent builder methods initialize an `exports` directory by default. A DTO created directly without `path` writes at the disk root. `CSVExportResult::path` is an absolute path when the adapter supports `path()` and otherwise contains the storage key. The result metadata always includes `storage_path`, and `fileExists()` uses the configured disk.

The package does not neutralize spreadsheet formulas. If untrusted fields will be opened in Excel or similar software, the application must apply its chosen CSV/formula-injection policy before export.

## Asynchronous processing

```php
use Illuminate\Bus\Batch;
use Nvl\Csv\Services\CSVAsyncProcessor;

$batch = CSVAsyncProcessor::make()
    ->fromFile($path)
    ->withOptions($options)
    ->withChunkSize(1_000)
    ->mapField('email', 'email')
    ->processRow(static function (array $row, int $rowNumber): void {
        // Execute idempotent application work.
    })
    ->onBatchComplete(static function (int $chunk, int $processed, array $errors): void {
        // Per-job summary.
    })
    ->onProgress(static function (Batch $batch): void {
        // Laravel batch progress callback.
    })
    ->onComplete(static function (Batch $batch): void {
        // Laravel batch finally callback.
    })
    ->processAsync();
```

`processAsyncWithTracking()` returns a batch ID and stores operational metadata under `csv_batch_metadata` on the local disk. Read it with `getBatchStatus($id)` and cancel work with `cancelBatch($id)`.

Callbacks and mapping closures are wrapped for Laravel serialization, but everything they capture must still be serializable. Do not capture open resources, active HTTP requests, service containers, or non-serializable third-party clients. Row work should be idempotent because queue retries can execute it again. Laravel batch callbacks run in the queue environment and must not use `$this`. Row-level exceptions are reported to `onBatchComplete` and do not fail the containing job; infrastructure or callback failures still use Laravel’s normal retry and failed-job behavior.

## Validators, transformers, filters, and value objects

The compatibility surface includes:

- `CSVFieldValidator` and `CSVRowValidator`
- `StringTransformer`, `NumericTransformer`, `DateTransformer`, `ChainedTransformer`, and `ConditionalTransformer`
- `CSVFilter::field()`, `custom()`, `all()`, and `any()`
- `CSVConfiguration`, `CSVFieldMapping`, `CSVImportResult`, and `CSVExportResult`
- typed enums for delimiter, encoding, export format, processing mode, field type, quality, error level, operation status, duplicate strategy, and notification channel
- `CSVAnalysisResultData`, `CSVImportOptionsData`, `CSVExportOptionsData`, and `CSVProgressData`

The Data objects are registered with `nvl/data` for generated TypeScript discovery.

## Agent guidance

Publish the bundled Laravel Boost skill when the consumer wants repository-local guidance:

```bash
php artisan vendor:publish --tag=csv-skills
```

This publishes `nvl-csv` into the application’s `.agents/skills` directory.

## Security and operational boundaries

Treat CSV input as untrusted. Enforce upload size, accepted MIME/extension policy, authorization, virus scanning, retention, and storage visibility before handing a source to this package. Define required mappings and validators before persistence. Keep error payloads away from public responses when source rows contain personal or confidential values.

The analyzer samples data and the import result retains failed-row payloads, so consumers processing sensitive or extremely error-prone files should bound source size and error tolerance. Queue batch metadata is operational convenience data rather than durable business state.

## Development and verification

Run the isolated package tests:

```bash
vendor/bin/pest \
  --test-directory=packages/nvl/csv/tests \
  --configuration=packages/nvl/csv/phpunit.xml.dist \
  --bootstrap=vendor/autoload.php \
  --compact \
  packages/nvl/csv/tests
```

Run the package quality checks:

```bash
composer quality --working-dir=packages/nvl/csv
```

From the monorepo root, also run:

```bash
vendor/bin/pint --dirty --format agent
composer packages:analyse
composer dependencies:check
composer packages:validate
```

The package is held to maximum PHPStan strictness, the monorepo's measured line-coverage baseline, and its 90% changed-line coverage requirement.

## License

`nvl/csv` is open-source software licensed under the [MIT license](LICENSE).
