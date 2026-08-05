<?php

declare(strict_types=1);

namespace Nvl\Csv\ValueObjects;

use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVExportFormatEnum;
use Nvl\Csv\Enums\CSVProcessingModeEnum;
use Nvl\Csv\Exceptions\CSVConfigurationException;

/**
 * Immutable CSV configuration value object for all CSV operations.
 *
 * Centralized configuration for parsing, generation, and processing settings.
 * Provides factory methods for common scenarios and immutable modification methods.
 * All properties are readonly for thread safety and predictable behavior.
 *
 * Key configuration areas:
 * - Format settings: delimiter, enclosure, escape characters
 * - Processing mode: memory vs streaming vs chunked processing
 * - Output options: headers, BOM, encoding
 * - Performance tuning: chunk size, memory limits
 */
final readonly class CSVConfiguration
{
    /**
     * Create immutable CSV configuration with comprehensive settings.
     *
     * @param  string  $delimiter  Field separator character (default: comma)
     * @param  string  $enclosure  Quote character for fields containing delimiters
     * @param  string  $escape  Character for escaping special characters
     * @param  string  $lineEnding  Row terminator sequence (\n, \r\n, \r)
     * @param  bool  $includeBOM  Whether to include UTF-8 BOM for Excel compatibility
     * @param  bool  $includeHeaders  Whether to include column headers in output
     * @param  bool  $includeIndex  Whether to include row index in output
     * @param  int|null  $chunkSize  Number of rows per processing chunk (null = auto)
     * @param  CSVProcessingModeEnum  $processingMode  Memory vs streaming strategy
     * @param  CSVExportFormatEnum|null  $exportFormat  Preset format configuration
     * @param  string|null  $encoding  Character encoding (default: UTF-8)
     * @param  int|null  $memoryLimit  Maximum memory usage in bytes
     * @param  bool  $strictMode  Enable strict validation and error handling
     * @param  bool  $skipEmptyRows  Ignore rows with all empty fields
     * @param  bool  $trimValues  Remove leading/trailing whitespace from values
     */
    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public string $escape = '\\',
        public string $lineEnding = "\n",
        public bool $includeBOM = false,
        public bool $includeHeaders = true,
        public bool $includeIndex = false,
        public ?int $chunkSize = null,
        public CSVProcessingModeEnum $processingMode = CSVProcessingModeEnum::MEMORY,
        public ?CSVExportFormatEnum $exportFormat = null,
        public ?string $encoding = 'UTF-8',
        public ?int $memoryLimit = null,
        public bool $strictMode = false,
        public bool $skipEmptyRows = true,
        public bool $trimValues = true,
    ) {
        if (strlen($this->delimiter) !== 1) {
            throw CSVConfigurationException::invalidDelimiter($this->delimiter);
        }

        if (strlen($this->enclosure) !== 1) {
            throw CSVConfigurationException::invalidEnclosure($this->enclosure);
        }

        if ($this->escape !== '' && strlen($this->escape) !== 1) {
            throw new CSVConfigurationException('CSV escape must be empty or exactly one byte.');
        }

        if ($this->lineEnding === '') {
            throw new CSVConfigurationException('CSV line ending cannot be empty.');
        }

        if ($this->chunkSize !== null && $this->chunkSize < 0) {
            throw CSVConfigurationException::invalidChunkSize($this->chunkSize);
        }

        if ($this->memoryLimit !== null && $this->memoryLimit !== -1 && $this->memoryLimit < 1) {
            throw CSVConfigurationException::invalidMemoryLimit($this->memoryLimit);
        }
    }

    /**
     * Create configuration from a predefined export format.
     *
     * Uses format-specific settings for optimal compatibility with target applications.
     * Automatically configures delimiter, enclosure, escape, line endings, and BOM.
     *
     * @param  CSVExportFormatEnum  $format  Predefined format (Excel, RFC4180, etc.)
     * @return self Configuration optimized for the specified format
     */
    public static function fromFormat(CSVExportFormatEnum $format): self
    {
        $settings = $format->getSettings();

        return new self(
            delimiter: $settings['delimiter'],
            enclosure: $settings['enclosure'],
            escape: $settings['escape'],
            lineEnding: $settings['line_ending'],
            includeBOM: $settings['include_bom'],
            exportFormat: $format,
        );
    }

    /**
     * Create configuration with a specific delimiter type.
     *
     * Quick factory method for changing only the field delimiter while
     * keeping all other settings at their defaults.
     *
     * @param  CSVDelimiterEnum  $delimiter  Field delimiter type
     * @return self Configuration with specified delimiter
     */
    public static function fromDelimiter(CSVDelimiterEnum $delimiter): self
    {
        return new self(
            delimiter: $delimiter->getCharacter(),
        );
    }

    /**
     * Create configuration with standard CSV defaults.
     *
     * Provides RFC 4180-compatible settings suitable for most CSV operations.
     * Uses comma delimiter, double-quote enclosure, and memory processing.
     *
     * @return self Standard CSV configuration
     */
    public static function default(): self
    {
        return new self;
    }

    /**
     * Create configuration optimized for Microsoft Excel compatibility.
     *
     * Uses Excel-specific settings including UTF-8 BOM for proper character
     * encoding recognition and Windows line endings.
     *
     * @return self Excel-compatible configuration
     */
    public static function excel(): self
    {
        return self::fromFormat(CSVExportFormatEnum::EXCEL);
    }

    /**
     * Create configuration optimized for processing large CSV files.
     *
     * Uses streaming mode with chunked processing and conservative memory limits.
     * Ideal for files too large to fit in memory (>100MB or >100k rows).
     *
     * @return self Large file processing configuration
     */
    public static function largeFile(): self
    {
        return new self(
            processingMode: CSVProcessingModeEnum::STREAM,
            chunkSize: 1000,
            memoryLimit: 128 * 1024 * 1024, // 128 MB
            skipEmptyRows: true,
            trimValues: true,
        );
    }

    /**
     * Create a new configuration with a different delimiter.
     *
     * Immutable modification method that creates a new instance with
     * the specified delimiter while preserving all other settings.
     *
     * @param  string  $delimiter  New field delimiter character
     * @return self New configuration instance with modified delimiter
     */
    public function withDelimiter(string $delimiter): self
    {
        return new self(
            delimiter: $delimiter,
            enclosure: $this->enclosure,
            escape: $this->escape,
            lineEnding: $this->lineEnding,
            includeBOM: $this->includeBOM,
            includeHeaders: $this->includeHeaders,
            includeIndex: $this->includeIndex,
            chunkSize: $this->chunkSize,
            processingMode: $this->processingMode,
            exportFormat: $this->exportFormat,
            encoding: $this->encoding,
            memoryLimit: $this->memoryLimit,
            strictMode: $this->strictMode,
            skipEmptyRows: $this->skipEmptyRows,
            trimValues: $this->trimValues,
        );
    }

    /**
     * Create a new configuration with a different processing mode.
     *
     * Automatically adjusts chunk size based on the new mode's defaults.
     * Useful for switching between memory and streaming processing strategies.
     *
     * @param  CSVProcessingModeEnum  $mode  New processing strategy
     * @return self New configuration instance with modified processing mode
     */
    public function withProcessingMode(CSVProcessingModeEnum $mode): self
    {
        return new self(
            delimiter: $this->delimiter,
            enclosure: $this->enclosure,
            escape: $this->escape,
            lineEnding: $this->lineEnding,
            includeBOM: $this->includeBOM,
            includeHeaders: $this->includeHeaders,
            includeIndex: $this->includeIndex,
            chunkSize: $mode->getDefaultChunkSize() ?: $this->chunkSize, // Use mode default or keep current
            processingMode: $mode,
            exportFormat: $this->exportFormat,
            encoding: $this->encoding,
            memoryLimit: $this->memoryLimit,
            strictMode: $this->strictMode,
            skipEmptyRows: $this->skipEmptyRows,
            trimValues: $this->trimValues,
        );
    }

    /**
     * Create a new configuration with a specific chunk size.
     *
     * Sets the number of rows to process in each batch. Larger chunks
     * improve performance but use more memory. Zero disables chunking.
     *
     * @param  int  $chunkSize  Number of rows per chunk (0 = no chunking)
     * @return self New configuration instance with modified chunk size
     */
    public function withChunkSize(int $chunkSize): self
    {
        return new self(
            delimiter: $this->delimiter,
            enclosure: $this->enclosure,
            escape: $this->escape,
            lineEnding: $this->lineEnding,
            includeBOM: $this->includeBOM,
            includeHeaders: $this->includeHeaders,
            includeIndex: $this->includeIndex,
            chunkSize: $chunkSize,
            processingMode: $this->processingMode,
            exportFormat: $this->exportFormat,
            encoding: $this->encoding,
            memoryLimit: $this->memoryLimit,
            strictMode: $this->strictMode,
            skipEmptyRows: $this->skipEmptyRows,
            trimValues: $this->trimValues,
        );
    }

    /**
     * Create a new configuration with row indexing enabled or disabled.
     *
     * @param  bool  $includeIndex  Whether to prepend a 1-based index column
     * @return self New configuration instance with modified index behavior
     */
    public function withIncludeIndex(bool $includeIndex = true): self
    {
        return new self(
            delimiter: $this->delimiter,
            enclosure: $this->enclosure,
            escape: $this->escape,
            lineEnding: $this->lineEnding,
            includeBOM: $this->includeBOM,
            includeHeaders: $this->includeHeaders,
            includeIndex: $includeIndex,
            chunkSize: $this->chunkSize,
            processingMode: $this->processingMode,
            exportFormat: $this->exportFormat,
            encoding: $this->encoding,
            memoryLimit: $this->memoryLimit,
            strictMode: $this->strictMode,
            skipEmptyRows: $this->skipEmptyRows,
            trimValues: $this->trimValues,
        );
    }

    /**
     * Check if this configuration uses streaming processing.
     *
     * Streaming modes process data sequentially with minimal memory usage,
     * suitable for large files that don't fit in memory.
     *
     * @return bool True if using streaming or lazy processing mode
     */
    public function isStreaming(): bool
    {
        return in_array($this->processingMode, [
            CSVProcessingModeEnum::STREAM, // Row-by-row processing
            CSVProcessingModeEnum::LAZY,   // Generator-based streaming
        ], true);
    }

    /**
     * Check if this configuration uses chunked processing.
     *
     * Chunked processing divides large datasets into smaller batches
     * for balanced memory usage and processing efficiency.
     *
     * @return bool True if chunk size is configured and greater than zero
     */
    public function isChunked(): bool
    {
        return $this->chunkSize !== null && $this->chunkSize > 0;
    }

    /**
     * Get the actual chunk size that will be used for processing.
     *
     * Returns the configured chunk size if set, otherwise falls back
     * to the processing mode's default, or 1000 as final fallback.
     *
     * @return int Effective chunk size for processing operations
     */
    public function getEffectiveChunkSize(): int
    {
        // Use explicit chunk size if configured
        if ($this->chunkSize !== null && $this->chunkSize > 0) {
            return $this->chunkSize;
        }

        // Fall back to processing mode default, then global default
        return $this->processingMode->getDefaultChunkSize() ?: 1000;
    }

    /**
     * Convert configuration to associative array format.
     *
     * Provides array representation suitable for serialization,
     * logging, debugging, and API responses. Keys use snake_case
     * convention for consistency with Laravel standards.
     *
     * @return array<string, mixed> Configuration as key-value array
     */
    public function toArray(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'escape' => $this->escape,
            'line_ending' => $this->lineEnding,
            'include_bom' => $this->includeBOM,
            'include_headers' => $this->includeHeaders,
            'include_index' => $this->includeIndex,
            'chunk_size' => $this->chunkSize,
            'processing_mode' => $this->processingMode->value,
            'export_format' => $this->exportFormat?->value,
            'encoding' => $this->encoding,
            'memory_limit' => $this->memoryLimit,
            'strict_mode' => $this->strictMode,
            'skip_empty_rows' => $this->skipEmptyRows,
            'trim_values' => $this->trimValues,
        ];
    }
}
