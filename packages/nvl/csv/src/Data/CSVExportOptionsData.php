<?php

declare(strict_types=1);

namespace Nvl\Csv\Data;

use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Enums\CSVExportFormatEnum;
use Nvl\Csv\Enums\CSVProcessingModeEnum;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class CSVExportOptionsData extends Data
{
    use DataTransform;

    /**
     * Create export options data.
     *
     * @param  string|Optional  $disk  Storage disk name
     * @param  string|Optional  $path  Export path
     * @param  string  $filename  Export filename
     * @param  CSVExportFormatEnum|Optional  $format  Export format
     * @param  CSVDelimiterEnum|Optional  $delimiter  Delimiter enum
     * @param  string|Optional  $enclosure  Enclosure character
     * @param  string|Optional  $escape  Escape character
     * @param  bool|Optional  $includeBom  Include BOM flag
     * @param  bool|Optional  $includeHeaders  Include headers flag
     * @param  bool|Optional  $includeIndex  Include index column
     * @param  CSVProcessingModeEnum|Optional  $processingMode  Processing mode
     * @param  int|null|Optional  $chunkSize  Chunk size for processing
     * @param  array<int, string>|Optional  $headings  Column headings
     * @param  array<int, string>|Optional  $descriptions  Column descriptions
     * @param  array<int, string>|Optional  $fields  Field keys to export
     * @param  CSVEncodingEnum|Optional  $encoding  File encoding
     * @param  int|null|Optional  $memoryLimit  Memory limit in bytes
     * @param  array<string, mixed>|Optional  $metadata  Additional metadata
     * @return void
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $disk,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $path,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $filename,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVExportFormatEnum::class)]
        public readonly CSVExportFormatEnum|Optional $format,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVDelimiterEnum::class)]
        public readonly CSVDelimiterEnum|Optional $delimiter,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $enclosure,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string|Optional $escape,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $includeBom,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $includeHeaders,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $includeIndex,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVProcessingModeEnum::class)]
        public readonly CSVProcessingModeEnum|Optional $processingMode,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|null|Optional $chunkSize,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array|Optional $headings,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array|Optional $descriptions,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array|Optional $fields,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVEncodingEnum::class)]
        public readonly CSVEncodingEnum|Optional $encoding,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|null|Optional $memoryLimit,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $metadata,
    ) {}

    /**
     * Default values for export options.
     *
     * @return array<string, mixed> Default options
     */
    public static function defaults(): array
    {
        return [
            'disk' => 'local',
            'path' => 'exports',
            'filename' => 'export_'.date('Y-m-d_His').'.csv',
            'format' => CSVExportFormatEnum::STANDARD,
            'delimiter' => CSVDelimiterEnum::COMMA,
            'enclosure' => '"',
            'escape' => '\\',
            'includeBom' => false,
            'includeHeaders' => true,
            'includeIndex' => false,
            'processingMode' => CSVProcessingModeEnum::MEMORY,
            'chunkSize' => null,
            'headings' => [],
            'descriptions' => [],
            'fields' => [],
            'encoding' => CSVEncodingEnum::UTF8,
            'memoryLimit' => null,
            'metadata' => [],
        ];
    }

    /**
     * Create options for Excel export.
     *
     * @param  string  $filename  Export filename
     * @return self Export options
     */
    public static function excel(string $filename): self
    {
        return self::from([
            'filename' => $filename,
            'format' => CSVExportFormatEnum::EXCEL,
            'includeBom' => true,
        ]);
    }

    /**
     * Create options for large file export.
     *
     * @param  string  $filename  Export filename
     * @param  int  $chunkSize  Chunk size
     * @return self Export options
     */
    public static function largeFile(string $filename, int $chunkSize = 1000): self
    {
        return self::from([
            'filename' => $filename,
            'processingMode' => CSVProcessingModeEnum::CHUNKED,
            'chunkSize' => $chunkSize,
            'memoryLimit' => 128 * 1024 * 1024, // 128 MB
        ]);
    }

    /**
     * Get full file path.
     *
     * @return string Full export path
     */
    public function getFullPath(): string
    {
        $parts = array_filter([
            $this->path instanceof Optional ? null : $this->path,
            $this->filename,
        ], fn ($part) => $part !== null && $part !== '');

        return implode('/', $parts);
    }

    /**
     * Check if export should be chunked.
     *
     * @return bool True when chunked processing is enabled
     */
    public function isChunked(): bool
    {
        if ($this->chunkSize instanceof Optional) {
            return false;
        }

        return $this->chunkSize !== null && $this->chunkSize > 0;
    }

    /**
     * Get effective delimiter character.
     *
     * @return string Delimiter character
     */
    public function getDelimiterCharacter(): string
    {
        if ($this->delimiter instanceof Optional) {
            return ',';
        }

        return $this->delimiter->getCharacter();
    }

    /**
     * Get encoding for file writing.
     *
     * @return CSVEncodingEnum Encoding enum
     */
    public function getEncoding(): CSVEncodingEnum
    {
        if ($this->encoding instanceof Optional) {
            return CSVEncodingEnum::UTF8;
        }

        return $this->encoding;
    }

    /**
     * Validation rules.
     *
     * @return array<string, array<int, string>> Validation rules
     */
    public static function rules(): array
    {
        return [
            'disk' => ['nullable', 'string'],
            'path' => ['nullable', 'string'],
            'filename' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'format' => ['nullable', 'string'],
            'delimiter' => ['nullable', 'string'],
            'enclosure' => ['nullable', 'string', 'max:1'],
            'escape' => ['nullable', 'string', 'max:1'],
            'includeBom' => ['nullable', 'boolean'],
            'includeHeaders' => ['nullable', 'boolean'],
            'includeIndex' => ['nullable', 'boolean'],
            'processingMode' => ['nullable', 'string'],
            'chunkSize' => ['nullable', 'integer', 'min:1'],
            'headings' => ['nullable', 'array'],
            'descriptions' => ['nullable', 'array'],
            'fields' => ['nullable', 'array'],
            'encoding' => ['nullable', 'string'],
            'memoryLimit' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Validation messages.
     *
     * @return array<string, mixed> Validation messages
     */
    public static function messages(): array
    {
        return self::translatedMessages('csv/validation');
    }

    /**
     * Validation attributes.
     *
     * @return array<string, string> Validation attribute labels
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('csv/validation');
    }
}
