<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Data\CSVExportOptionsData;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Enums\CSVExportFormatEnum;
use Nvl\Csv\Exceptions\CSVConfigurationException;
use Nvl\Csv\Services\CSVExport;
use Nvl\Csv\Tests\Fixtures\CsvExportRecord;
use Nvl\Csv\ValueObjects\CSVConfiguration;

beforeEach(function (): void {
    Storage::fake('csv-exports');
});

it('exports arrays with DTO-controlled dialect, headings, fields, and BOM', function (): void {
    $options = CSVExportOptionsData::from([
        'disk' => 'csv-exports',
        'path' => 'reports',
        'filename' => 'people.csv',
        'format' => CSVExportFormatEnum::RFC4180,
        'delimiter' => CSVDelimiterEnum::SEMICOLON,
        'includeBom' => true,
        'includeHeaders' => true,
        'headings' => ['Name', 'City'],
        'fields' => ['profile.name', 'city'],
    ]);

    $result = CSVExport::make()
        ->withOptions($options)
        ->fromArray([
            ['profile' => ['name' => 'Jane'], 'city' => 'Sofia'],
            ['profile' => ['name' => 'John'], 'city' => 'Plovdiv'],
        ]);

    $contents = Storage::disk('csv-exports')->get('reports/people.csv');

    expect($contents)->toStartWith("\xEF\xBB\xBF")
        ->and($contents)->toContain("Name;City\r\n")
        ->and($contents)->toContain("Jane;Sofia\r\n")
        ->and($result->isSuccessful())->toBeTrue()
        ->and($result->rowCount)->toBe(2)
        ->and($result->columnCount)->toBe(2)
        ->and($result->fileExists())->toBeTrue()
        ->and($result->metadata['storage_path'])->toBe('reports/people.csv')
        ->and($result->toDownloadResponse()['success'])->toBeTrue();
});

it('preserves closure field extractors and index configuration', function (): void {
    $result = CSVExport::make()
        ->configure(CSVConfiguration::excel()->withIncludeIndex())
        ->disk('csv-exports')
        ->path('computed')
        ->filename('computed.csv')
        ->headings(['Label', 'Total'])
        ->fields([
            'label',
            fn (array $row): int => $row['quantity'] * $row['price'],
        ])
        ->fromArray([
            ['label' => 'First', 'quantity' => 2, 'price' => 5],
        ]);

    $contents = Storage::disk('csv-exports')->get('computed/computed.csv');

    expect($contents)->toStartWith("\xEF\xBB\xBF")
        ->and($contents)->toContain("#,Label,Total\r\n")
        ->and($contents)->toContain("1,First,10\r\n")
        ->and($result->columnCount)->toBe(3);
});

it('streams UTF-16 output as one consistently encoded file', function (): void {
    $options = CSVExportOptionsData::from([
        'disk' => 'csv-exports',
        'filename' => 'utf16.csv',
        'encoding' => CSVEncodingEnum::UTF16_LE,
        'headings' => ['Name', 'City'],
        'fields' => ['name', 'city'],
    ]);

    CSVExport::make()
        ->withOptions($options)
        ->fromArray([['name' => 'Иван', 'city' => 'София']]);

    $contents = Storage::disk('csv-exports')->get('utf16.csv');
    $decoded = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');

    expect($contents)->toStartWith("\xFF\xFE")
        ->and($decoded)->toContain("Name,City\n")
        ->and($decoded)->toContain("Иван,София\n");
});

it('exports collections and callback-provided streams', function (): void {
    $collectionResult = CSVExport::make()
        ->disk('csv-exports')
        ->filename('collection.csv')
        ->headings(['ID', 'Name'])
        ->fields(['id', 'name'])
        ->fromCollection(new Collection([
            ['id' => 1, 'name' => 'One'],
            ['id' => 2, 'name' => 'Two'],
        ]));

    $streamResult = CSVExport::make()
        ->disk('csv-exports')
        ->filename('stream.csv')
        ->headings(['ID'])
        ->fields(['id'])
        ->stream(function (Closure $write): void {
            $write([['id' => 1], ['id' => 2]]);
            $write(new Collection([['id' => 3]]));
        });

    expect($collectionResult->rowCount)->toBe(2)
        ->and($streamResult->rowCount)->toBe(3)
        ->and(Storage::disk('csv-exports')->get('exports/stream.csv'))->toContain("3\n");
});

it('exports Eloquent queries in bounded chunks', function (): void {
    Schema::create('csv_export_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    CsvExportRecord::query()->insert([
        ['name' => 'One'],
        ['name' => 'Two'],
        ['name' => 'Three'],
    ]);

    $result = CSVExport::make()
        ->configure(CSVConfiguration::default()->withChunkSize(2))
        ->disk('csv-exports')
        ->filename('query.csv')
        ->headings(['ID', 'Name'])
        ->fields(['id', 'name'])
        ->fromQuery(CsvExportRecord::query()->orderBy('id'));

    expect($result->rowCount)->toBe(3)
        ->and(Storage::disk('csv-exports')->get('exports/query.csv'))->toContain("3,Three\n");
});

it('supports chunked arrays, headerless files, and empty export results', function (): void {
    $headerless = CSVExport::make()
        ->configure(new CSVConfiguration(includeHeaders: false))
        ->disk('csv-exports')
        ->filename('headerless.csv')
        ->fields(['id'])
        ->chunked(1)
        ->fromArray([['id' => 1], ['id' => 2]]);

    $empty = CSVExport::make()
        ->disk('csv-exports')
        ->filename('empty.csv')
        ->headings(['ID'])
        ->fromArray([]);

    expect(Storage::disk('csv-exports')->get('exports/headerless.csv'))->toBe("1\n2\n")
        ->and($headerless->rowCount)->toBe(2)
        ->and($empty->isSuccessful())->toBeFalse()
        ->and($empty->hasErrors())->toBeFalse()
        ->and($empty->getRowsPerSecond())->toBe(0.0)
        ->and($empty->toArray()['successful'])->toBeFalse();
});

it('infers headings and serializes common PHP value objects predictably', function (): void {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'display value';
        }
    };

    CSVExport::make()
        ->disk('csv-exports')
        ->filename('inferred.csv')
        ->fromArray([[
            'created_at' => new DateTimeImmutable('2026-01-02T03:04:05+02:00'),
            'label' => $stringable,
            'state' => CSVEncodingEnum::UTF8,
        ]]);

    expect(Storage::disk('csv-exports')->get('exports/inferred.csv'))->toBe(
        "created_at,label,state\n2026-01-02T03:04:05+02:00,\"display value\",UTF-8\n",
    );
});

it('does not write a UTF-8 BOM for legacy single-byte encodings', function (): void {
    $options = CSVExportOptionsData::from([
        'disk' => 'csv-exports',
        'filename' => 'latin.csv',
        'encoding' => CSVEncodingEnum::ISO_8859_1,
        'includeBom' => true,
        'headings' => ['Name'],
        'fields' => ['name'],
    ]);

    CSVExport::make()->withOptions($options)->fromArray([['name' => 'André']]);

    $contents = Storage::disk('csv-exports')->get('latin.csv');
    expect($contents)->not->toStartWith("\xEF\xBB\xBF")
        ->and(mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1'))->toBe("Name\nAndré\n");
});

it('rejects invalid disks and unsupported row values', function (): void {
    expect(fn () => CSVExport::make()->disk('missing-disk'))
        ->toThrow(CSVConfigurationException::class);

    $resource = fopen('php://temp', 'r+');
    expect($resource)->not->toBeFalse();

    try {
        expect(fn () => CSVExport::make()
            ->disk('csv-exports')
            ->filename('invalid.csv')
            ->fromArray([['resource' => $resource]]))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});
