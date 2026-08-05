<?php

declare(strict_types=1);

namespace Nvl\Csv\Data;

use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVDuplicateStrategyEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
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
final class CSVImportOptionsData extends Data
{
    use DataTransform;

    /**
     * Create import options data.
     *
     * @param  string  $filePath  CSV file path
     * @param  CSVDelimiterEnum|Optional  $delimiter  Delimiter enum
     * @param  string|Optional  $enclosure  Enclosure character
     * @param  string|Optional  $escape  Escape character
     * @param  CSVProcessingModeEnum|Optional  $processingMode  Processing mode
     * @param  int|null|Optional  $chunkSize  Chunk size for processing
     * @param  int|Optional  $skipRows  Number of rows to skip
     * @param  int|null|Optional  $limitRows  Maximum rows to process
     * @param  bool|Optional  $hasHeaders  Header row flag
     * @param  array<string, mixed>|Optional  $columnMapping  Column mapping configuration
     * @param  array<string, mixed>|Optional  $columnTypes  Column type configuration
     * @param  bool|Optional  $skipEmptyRows  Skip empty rows
     * @param  bool|Optional  $trimValues  Trim field values
     * @param  bool|Optional  $validateData  Validate data flag
     * @param  bool|Optional  $strictMode  Strict mode flag
     * @param  CSVEncodingEnum|Optional  $encoding  File encoding
     * @param  int|null|Optional  $memoryLimit  Memory limit in bytes
     * @param  array<int, string>|Optional  $uniqueFields  Unique field names
     * @param  CSVDuplicateStrategyEnum|Optional  $duplicateStrategy  Duplicate strategy
     * @param  array<string, mixed>|Optional  $metadata  Additional metadata
     * @return void
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $filePath,
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
        #[TypeScriptType(CSVProcessingModeEnum::class)]
        public readonly CSVProcessingModeEnum|Optional $processingMode,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|null|Optional $chunkSize,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $skipRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|null|Optional $limitRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $hasHeaders,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $columnMapping,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $columnTypes,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $skipEmptyRows,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $trimValues,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $validateData,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $strictMode,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVEncodingEnum::class)]
        public readonly CSVEncodingEnum|Optional $encoding,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|null|Optional $memoryLimit,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array|Optional $uniqueFields,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVDuplicateStrategyEnum::class)]
        public readonly CSVDuplicateStrategyEnum|Optional $duplicateStrategy,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $metadata,
    ) {}

    /**
     * Default values for import options.
     *
     * @return array<string, mixed> Default options
     */
    public static function defaults(): array
    {
        return [
            'delimiter' => CSVDelimiterEnum::COMMA,
            'enclosure' => '"',
            'escape' => '\\',
            'processingMode' => CSVProcessingModeEnum::MEMORY,
            'chunkSize' => null,
            'skipRows' => 0,
            'limitRows' => null,
            'hasHeaders' => true,
            'columnMapping' => [],
            'columnTypes' => [],
            'skipEmptyRows' => true,
            'trimValues' => true,
            'validateData' => true,
            'strictMode' => false,
            'encoding' => CSVEncodingEnum::UTF8,
            'memoryLimit' => null,
            'uniqueFields' => [],
            'duplicateStrategy' => CSVDuplicateStrategyEnum::SKIP,
            'metadata' => [],
        ];
    }

    /**
     * Create options for large file import.
     *
     * @param  string  $filePath  CSV file path
     * @param  int  $chunkSize  Chunk size
     * @return self Import options
     */
    public static function largeFile(string $filePath, int $chunkSize = 1000): self
    {
        return self::from([
            'filePath' => $filePath,
            'processingMode' => CSVProcessingModeEnum::CHUNKED,
            'chunkSize' => $chunkSize,
            'memoryLimit' => 128 * 1024 * 1024, // 128 MB
        ]);
    }

    /**
     * Create options for strict import.
     *
     * @param  string  $filePath  CSV file path
     * @return self Import options
     */
    public static function strict(string $filePath): self
    {
        return self::from([
            'filePath' => $filePath,
            'validateData' => true,
            'strictMode' => true,
            'skipEmptyRows' => false,
        ]);
    }

    /**
     * Check if import should be chunked.
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
     * Check if import should validate data.
     *
     * @return bool True when validation is enabled
     */
    public function shouldValidate(): bool
    {
        if ($this->validateData instanceof Optional) {
            return true;
        }

        return $this->validateData;
    }

    /**
     * Get duplicate handling strategy.
     *
     * @return CSVDuplicateStrategyEnum Duplicate handling strategy
     */
    public function getDuplicateStrategy(): CSVDuplicateStrategyEnum
    {
        if ($this->duplicateStrategy instanceof Optional) {
            return CSVDuplicateStrategyEnum::SKIP;
        }

        return $this->duplicateStrategy;
    }

    /**
     * Get encoding for file reading.
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
     * Get effective skip rows count.
     *
     * @return int Skip rows count
     */
    public function getSkipRows(): int
    {
        if ($this->skipRows instanceof Optional) {
            return 0;
        }

        return max(0, $this->skipRows);
    }

    /**
     * Get the effective limit for processed rows.
     *
     * @return int|null Row limit
     */
    public function getLimitRows(): ?int
    {
        if ($this->limitRows instanceof Optional) {
            return null;
        }

        return $this->limitRows !== null && $this->limitRows > 0 ? $this->limitRows : null;
    }

    /**
     * Check if file has headers.
     *
     * @return bool True when header row is present
     */
    public function hasHeaderRow(): bool
    {
        if ($this->hasHeaders instanceof Optional) {
            return true;
        }

        return $this->hasHeaders;
    }

    /**
     * Validation rules.
     *
     * @return array<string, array<int, string>> Validation rules
     */
    public static function rules(): array
    {
        return [
            'filePath' => ['required', 'string'],
            'delimiter' => ['nullable', 'string'],
            'enclosure' => ['nullable', 'string', 'max:1'],
            'escape' => ['nullable', 'string', 'max:1'],
            'processingMode' => ['nullable', 'string'],
            'chunkSize' => ['nullable', 'integer', 'min:1'],
            'skipRows' => ['nullable', 'integer', 'min:0'],
            'limitRows' => ['nullable', 'integer', 'min:1'],
            'hasHeaders' => ['nullable', 'boolean'],
            'columnMapping' => ['nullable', 'array'],
            'columnTypes' => ['nullable', 'array'],
            'skipEmptyRows' => ['nullable', 'boolean'],
            'trimValues' => ['nullable', 'boolean'],
            'validateData' => ['nullable', 'boolean'],
            'strictMode' => ['nullable', 'boolean'],
            'encoding' => ['nullable', 'string'],
            'duplicateStrategy' => ['nullable', 'string'],
            'memoryLimit' => ['nullable', 'integer', 'min:1'],
            'uniqueFields' => ['nullable', 'array'],
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
