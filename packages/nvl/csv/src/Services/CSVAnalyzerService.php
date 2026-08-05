<?php

declare(strict_types=1);

namespace Nvl\Csv\Services;

use Carbon\Carbon;
use DivisionByZeroError;
use Exception;
use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Data\CSVAnalysisResultData;
use Nvl\Csv\Enums\CSVDataQualityEnum;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
use Nvl\Csv\Exceptions\CSVFileNotFoundException;
use RuntimeException;

/**
 * CSV file analyzer service for comprehensive file analysis.
 *
 * Analyzes CSV files to detect structure, encoding, data quality,
 * and provides recommendations for optimal processing strategies.
 */
final class CSVAnalyzerService
{
    private const SAMPLE_SIZE = 100;

    private const MAX_ANALYSIS_ROWS = 10000;

    /** @var resource|null */
    private $handle = null;

    private string $filePath = '';

    private ?string $sourceDisk = null;

    private ?string $sourcePath = null;

    private int $fileSize = 0;

    private CSVDelimiterEnum $delimiter = CSVDelimiterEnum::COMMA;

    private CSVEncodingEnum $encoding = CSVEncodingEnum::UTF8;

    private string $lineEnding = 'LF';

    private bool $hasBom = false;

    /** @var array<string> */
    private array $headers = [];

    /** @var array<array<mixed>> */
    private array $sampleData = [];

    private int $rowCount = 0;

    private int $analyzedRowCount = 0;

    private int $columnCount = 0;

    private int $emptyRowCount = 0;

    private int $inconsistentRowCount = 0;

    /** @var array<string, array{type: string, nullable: bool, sample: mixed}> */
    private array $columnAnalysis = [];

    /** @var array<string, int> */
    private array $duplicateAnalysis = [];

    /** @var array<string, array{min: mixed, max: mixed, avg: mixed}> */
    private array $numericStatistics = [];

    /** @var array<string, array{min: int, max: int, avg: float}> */
    private array $textStatistics = [];

    /** @var array<string, array{format: string, sample: string}> */
    private array $dateFormatAnalysis = [];

    /** @var array<array{severity: string, message: string, details?: mixed}> */
    private array $issues = [];

    /** @var array<string> */
    private array $recommendations = [];

    private float $validityScore = 0;

    private CSVDataQualityEnum $dataQuality = CSVDataQualityEnum::POOR;

    /**
     * Perform comprehensive analysis of a CSV file from filesystem path.
     *
     * Conducts detailed analysis including file structure detection, encoding analysis,
     * data quality assessment, column type inference, and processing recommendations.
     * Returns comprehensive results suitable for optimizing import operations.
     *
     * @param  string  $filePath  Absolute path to the CSV file to analyze
     * @return CSVAnalysisResultData Complete analysis results with recommendations and statistics
     *
     * @throws CSVFileNotFoundException If the specified file does not exist or cannot be accessed
     * @throws RuntimeException If file operations fail or system resources are unavailable
     * @throws Exception If analysis operations encounter unexpected errors
     * @throws DivisionByZeroError If statistical calculations encounter division by zero
     */
    public function analyzeFile(string $filePath): CSVAnalysisResultData
    {
        $startTime = microtime(true);

        if (! file_exists($filePath)) {
            throw CSVFileNotFoundException::fileNotFound($filePath);
        }

        $this->resetState();
        $this->filePath = $filePath;
        $this->fileSize = filesize($filePath) ?: 0;
        $this->sourceDisk = null;
        $this->sourcePath = null;

        return $this->analyzeCurrentSource($startTime);
    }

    /**
     * Analyze a CSV file from a Laravel storage disk.
     *
     * Uses Laravel's Storage facade to access and analyze files from configured
     * storage disks (local, s3, etc.). Provides the same comprehensive analysis
     * as analyzeFile but with storage abstraction.
     *
     * @param  string  $disk  Storage disk name (local, s3, public, etc.)
     * @param  string  $path  Path to the file on the specified storage disk
     * @return CSVAnalysisResultData Complete analysis results with recommendations and statistics
     *
     * @throws CSVFileNotFoundException If the file does not exist on the specified disk
     * @throws RuntimeException If file operations or disk access fails
     * @throws Exception If analysis operations encounter unexpected errors
     * @throws DivisionByZeroError If statistical calculations encounter division by zero
     */
    public function analyzeFromDisk(string $disk, string $path): CSVAnalysisResultData
    {
        $diskInstance = Storage::disk($disk);

        if (! $diskInstance->exists($path)) {
            throw CSVFileNotFoundException::fileNotFoundOnDisk($disk, $path);
        }

        $this->resetState();
        $this->filePath = "{$disk}://{$path}";
        $this->fileSize = $diskInstance->size($path);
        $this->sourceDisk = $disk;
        $this->sourcePath = $path;

        return $this->analyzeCurrentSource(microtime(true));
    }

    /**
     * Perform quick analysis for basic file properties and structure.
     *
     * Provides lightweight analysis focusing on essential file characteristics
     * like encoding, delimiter, headers, and basic structure. Suitable for
     * initial file validation and configuration hints.
     *
     * @param  string  $filePath  Absolute path to the CSV file to analyze
     * @return array<string, mixed> Basic file properties including size, encoding, delimiter, and headers
     *
     * @throws CSVFileNotFoundException If the specified file does not exist
     * @throws RuntimeException If file operations fail
     */
    public function quickAnalyze(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw CSVFileNotFoundException::fileNotFound($filePath);
        }

        $this->resetState();
        $this->filePath = $filePath;
        $this->fileSize = filesize($filePath) ?: 0;
        $this->sourceDisk = null;
        $this->sourcePath = null;

        try {
            $this->openFile();
            $this->detectFileProperties();

            // Read just headers and a few rows
            $this->readHeaders();
            $rowCount = 0;
            while (($row = $this->readRow()) !== false && $rowCount < 10) {
                $rowCount++;
            }

            return [
                'file_size' => $this->fileSize,
                'encoding' => $this->encoding->value,
                'delimiter' => $this->delimiter->value,
                'has_bom' => $this->hasBom,
                'line_ending' => $this->lineEnding,
                'column_count' => count($this->headers),
                'headers' => $this->headers,
                'sample_rows' => $rowCount,
            ];
        } finally {
            $this->closeFile();
        }
    }

    /**
     * Open the CSV file for binary reading and analysis.
     *
     * Opens the file in binary mode for accurate encoding detection
     * and raw data analysis. Prepares the file handle for analysis operations.
     *
     * @throws RuntimeException If the file cannot be opened for reading
     */
    private function openFile(): void
    {
        $handle = $this->sourceDisk !== null && $this->sourcePath !== null
            ? Storage::disk($this->sourceDisk)->readStream($this->sourcePath)
            : fopen($this->filePath, 'rb');

        if (! is_resource($handle)) {
            throw new RuntimeException("Unable to open file: {$this->filePath}");
        }

        $this->handle = $handle;
    }

    /**
     * Close the file handle and clean up resources.
     *
     * Safely closes the file handle if it's currently open and resets
     * the handle to null to prevent further operations.
     */
    private function closeFile(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * Detect fundamental file properties including encoding, delimiter, BOM, and line endings.
     *
     * Analyzes the first portion of the file to automatically detect structural
     * and encoding characteristics. This information is essential for proper
     * CSV parsing configuration.
     *
     * @throws RuntimeException If file reading operations fail
     */
    private function detectFileProperties(): void
    {
        if (! is_resource($this->handle)) {
            throw new RuntimeException('File handle is not open');
        }

        // Read first few KB for detection
        $sample = fread($this->handle, 8192);
        if ($sample === false) {
            $sample = '';
        }

        // Detect BOM and encoding
        $this->detectBomAndEncoding($sample);

        $utf8Sample = $this->convertSampleToUtf8($sample);
        $this->detectLineEnding($utf8Sample);
        $this->detectDelimiter($utf8Sample);
        $this->prepareInputStream();
    }

    /**
     * Detect Byte Order Mark (BOM) and character encoding from file sample.
     *
     * Analyzes the beginning of the file for BOM markers and attempts to
     * determine the character encoding. Critical for proper text interpretation.
     *
     * @param  string  $sample  Beginning portion of the file for analysis
     */
    private function detectBomAndEncoding(string $sample): void
    {
        // Check for BOM
        $bomBytes = substr($sample, 0, 4);
        $detectedEncoding = CSVEncodingEnum::detectFromBOM($bomBytes);

        if ($detectedEncoding !== null) {
            $this->hasBom = true;
            $this->encoding = $detectedEncoding;
        } else {
            // Try to detect encoding without BOM
            $this->encoding = $this->detectEncodingFromContent($sample);
        }
    }

    /**
     * Detect character encoding from file content using heuristic analysis.
     *
     * Uses PHP's mb_detect_encoding to analyze content patterns and determine
     * the most likely character encoding when no BOM is present.
     *
     * @param  string  $sample  File content sample for encoding detection
     * @return CSVEncodingEnum Detected character encoding
     */
    private function detectEncodingFromContent(string $sample): CSVEncodingEnum
    {
        // Try mb_detect_encoding
        $detected = mb_detect_encoding($sample, [
            'UTF-8',
            'ISO-8859-1',
            'Windows-1252',
            'ASCII',
        ], true);

        return match ($detected) {
            'UTF-8' => CSVEncodingEnum::UTF8,
            'ISO-8859-1' => CSVEncodingEnum::ISO_8859_1,
            'Windows-1252' => CSVEncodingEnum::WINDOWS_1252,
            'ASCII' => CSVEncodingEnum::ASCII,
            default => CSVEncodingEnum::UTF8,
        };
    }

    /**
     * Detect the line ending format used in the CSV file.
     *
     * Analyzes the file sample to determine whether the file uses Unix (LF),
     * Windows (CRLF), or classic Mac (CR) line endings.
     *
     * @param  string  $sample  File content sample for line ending detection
     */
    private function detectLineEnding(string $sample): void
    {
        if (str_contains($sample, "\r\n")) {
            $this->lineEnding = 'CRLF';
        } elseif (str_contains($sample, "\r")) {
            $this->lineEnding = 'CR';
        } else {
            $this->lineEnding = 'LF';
        }
    }

    /**
     * Detect the field delimiter character used in the CSV file.
     *
     * Analyzes the first line of the file to determine which delimiter
     * (comma, semicolon, tab, pipe) is most likely used based on occurrence frequency.
     *
     * @param  string  $sample  File content sample for delimiter detection
     */
    private function detectDelimiter(string $sample): void
    {
        $bestScore = 0;

        foreach (CSVDelimiterEnum::cases() as $candidate) {
            $columnCounts = $this->sampleColumnCounts($sample, $candidate->getCharacter());
            $firstCount = $columnCounts[0] ?? 0;
            $consistentRows = count(array_filter(
                $columnCounts,
                fn (int $count): bool => $count === $firstCount,
            ));
            $score = $firstCount > 1 ? $firstCount * $consistentRows : 0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $this->delimiter = $candidate;
            }
        }
    }

    /**
     * Parse logical CSV records from a sample for one delimiter candidate.
     *
     * @return list<int>
     */
    private function sampleColumnCounts(string $sample, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate a CSV dialect detection stream.');
        }

        try {
            fwrite($stream, $sample);
            rewind($stream);
            $columnCounts = [];

            while (count($columnCounts) < 5) {
                $row = fgetcsv($stream, 0, $delimiter, '"', '\\');
                if ($row === false) {
                    break;
                }

                if (count($row) === 1 && ($row[0] === '' || $row[0] === null)) {
                    continue;
                }

                $columnCounts[] = count($row);
            }

            return $columnCounts;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Analyze the structural characteristics of the CSV file.
     *
     * Examines file structure including row count, column consistency,
     * empty row detection, and structural integrity. Builds sample data
     * for further analysis while monitoring for structural issues.
     *
     * @throws RuntimeException If file reading operations fail
     * @throws DivisionByZeroError If row count calculations encounter division by zero
     */
    private function analyzeStructure(): void
    {
        if (! is_resource($this->handle)) {
            throw new RuntimeException('File handle is not open');
        }

        // Read headers
        $this->readHeaders();

        // Count rows and check consistency
        $this->rowCount = 0;
        $this->analyzedRowCount = 0;
        $expectedColumns = count($this->headers);

        while (($row = $this->readRow()) !== false) {
            $this->rowCount++;
            $this->analyzedRowCount++;

            // Store sample data
            if ($this->rowCount <= self::SAMPLE_SIZE) {
                $this->sampleData[] = $row;
            }

            // Check for empty rows
            $nonEmpty = array_filter($row, fn ($v) => $v !== '' && $v !== null);
            if (empty($nonEmpty)) {
                $this->emptyRowCount++;

                continue;
            }

            // Check column consistency
            if (count($row) !== $expectedColumns) {
                $this->inconsistentRowCount++;
                $this->issues[] = [
                    'severity' => 'warning',
                    'message' => "Row {$this->rowCount} has ".count($row)." columns, expected {$expectedColumns}",
                ];
            }

            // Limit analysis for very large files
            if ($this->rowCount >= self::MAX_ANALYSIS_ROWS) {
                // Estimate total rows
                $position = is_resource($this->handle) ? ftell($this->handle) : false;
                if ($position !== false && $position > 0) {
                    $percentRead = $position / $this->fileSize;
                    $this->rowCount = (int) ($this->rowCount / $percentRead);
                }
                break;
            }
        }

        $this->columnCount = $expectedColumns;
    }

    /**
     * Read and validate CSV column headers.
     *
     * Extracts the first row as column headers, validates their presence,
     * and checks for duplicate header names. Critical for understanding
     * the file's data structure.
     *
     * @throws RuntimeException If the file handle is not available or headers cannot be read
     */
    private function readHeaders(): void
    {
        if (! is_resource($this->handle)) {
            throw new RuntimeException('File handle is not open');
        }

        $headers = fgetcsv(
            $this->handle,
            0,
            $this->delimiter->getCharacter()
        );

        if ($headers === false) {
            $this->headers = [];
            $this->issues[] = [
                'severity' => 'critical',
                'message' => 'No headers found in file',
            ];
        } else {
            $this->headers = array_map(fn ($h) => is_string($h) ? trim($h) : (string) $h, $headers);

            // Check for duplicate headers
            $duplicates = array_count_values($this->headers);
            foreach ($duplicates as $header => $count) {
                if ($count > 1) {
                    $this->issues[] = [
                        'severity' => 'warning',
                        'message' => "Duplicate header found: '{$header}' appears {$count} times",
                    ];
                }
            }
        }
    }

    /**
     * Read the next row of data from the CSV file.
     *
     * Uses the detected delimiter and formatting to parse the next row
     * from the file. Returns false when end of file is reached.
     *
     * @return array<int, string|null>|false Array of column values, or false if end of file
     */
    private function readRow(): array|false
    {
        if (! is_resource($this->handle)) {
            return false;
        }

        return fgetcsv(
            $this->handle,
            0,
            $this->delimiter->getCharacter()
        );
    }

    /**
     * Assess overall data quality and calculate validity scores.
     *
     * Calculates data quality metrics based on the ratio of valid rows
     * to total rows, considering empty rows and structural inconsistencies.
     * Assigns overall quality rating.
     */
    private function analyzeDataQuality(): void
    {
        $validRows = $this->analyzedRowCount - $this->emptyRowCount - $this->inconsistentRowCount;
        $this->validityScore = $this->analyzedRowCount > 0
            ? round(($validRows / $this->analyzedRowCount) * 100, 2)
            : 0;

        $this->dataQuality = CSVDataQualityEnum::fromScore($this->validityScore);
    }

    /**
     * Analyze data types and calculate statistics for each column.
     *
     * Examines sample data to infer column data types (numeric, text, date, boolean),
     * calculates relevant statistics for each type, and detects duplicate values.
     * Essential for import optimization and data validation.
     *
     * @throws DivisionByZeroError If statistical calculations encounter division by zero
     */
    private function analyzeColumnTypes(): void
    {
        if (empty($this->sampleData)) {
            return;
        }

        foreach ($this->headers as $index => $header) {
            $columnValues = array_column($this->sampleData, $index);
            $columnValues = array_filter($columnValues, fn ($v) => $v !== '' && $v !== null);

            if (empty($columnValues)) {
                $this->columnAnalysis[$header] = [
                    'type' => 'unknown',
                    'nullable' => true,
                    'sample' => null,
                ];

                continue;
            }

            // Detect type
            $type = $this->detectColumnType($columnValues);
            $this->columnAnalysis[$header] = [
                'type' => $type,
                'nullable' => count($columnValues) < count($this->sampleData),
                'sample' => $columnValues[0] ?? null,
            ];

            // Calculate statistics based on type
            if ($type === 'numeric') {
                $this->calculateNumericStatistics($header, $columnValues);
            } elseif ($type === 'text') {
                $this->calculateTextStatistics($header, $columnValues);
            } elseif ($type === 'date') {
                $this->analyzeDateFormat($header, $columnValues);
            }

            // Check for duplicates
            $uniqueValues = array_unique(array_map(
                fn (mixed $value): string => $this->scalarString($value),
                $columnValues,
            ));
            $this->duplicateAnalysis[$header] = count($columnValues) - count($uniqueValues);
        }
    }

    /**
     * Detect the predominant data type for a column based on sample values.
     *
     * Analyzes sample values to determine the most likely data type
     * (numeric, date, boolean, text, or mixed). Uses statistical analysis
     * to ensure type consistency across the sample.
     *
     * @param  array<mixed>  $values  Sample values from the column
     * @return string Detected data type ('numeric', 'date', 'boolean', 'text', or 'mixed')
     *
     * @throws DivisionByZeroError If type consistency calculation encounters division by zero
     */
    private function detectColumnType(array $values): string
    {
        $types = [
            'numeric' => 0,
            'date' => 0,
            'boolean' => 0,
            'text' => 0,
        ];

        foreach ($values as $value) {
            if ($this->isBoolean($value)) {
                $types['boolean']++;
            } elseif (is_numeric($value)) {
                $types['numeric']++;
            } elseif ($this->isDate($value)) {
                $types['date']++;
            } else {
                $types['text']++;
            }
        }

        // Determine predominant type (at least 80% consistency)
        $total = count($values);
        foreach ($types as $type => $count) {
            if ($count / $total >= 0.8) {
                return $type;
            }
        }

        return 'mixed';
    }

    /**
     * Check if a value appears to be a date using pattern matching.
     *
     * Tests the value against common date format patterns to determine
     * if it represents a date. Supports various date formats including
     * ISO dates, US format, European format, and datetime combinations.
     *
     * @param  mixed  $value  Value to test for date format
     * @return bool True if the value matches common date patterns
     */
    private function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // Common date patterns
        $patterns = [
            '/^\d{4}-\d{2}-\d{2}$/',           // YYYY-MM-DD
            '/^\d{2}\/\d{2}\/\d{4}$/',         // DD/MM/YYYY or MM/DD/YYYY
            '/^\d{4}\/\d{2}\/\d{2}$/',         // YYYY/MM/DD
            '/^\d{2}-\d{2}-\d{4}$/',           // DD-MM-YYYY
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', // YYYY-MM-DD HH:MM:SS
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a value represents a boolean using common boolean representations.
     *
     * Tests the value against common textual representations of boolean values
     * including 'true'/'false', '1'/'0', 'yes'/'no', and their variations.
     *
     * @param  mixed  $value  Value to test for boolean representation
     * @return bool True if the value matches common boolean patterns
     */
    private function isBoolean(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $booleanValues = [
            'true', 'false', '1', '0',
            'yes', 'no', 'y', 'n',
            'TRUE', 'FALSE', 'YES', 'NO',
        ];

        return in_array($value, $booleanValues, true);
    }

    /**
     * Calculate statistical measures for numeric columns.
     *
     * Computes minimum, maximum, and average values for numeric data.
     * Provides insights into data distribution and range for optimization.
     *
     * @param  string  $column  Column name for storing results
     * @param  array<mixed>  $values  Numeric values from the column sample
     *
     * @throws DivisionByZeroError If average calculation encounters empty value set
     */
    private function calculateNumericStatistics(string $column, array $values): void
    {
        $numericValues = array_values(array_map(
            fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0,
            $values,
        ));

        $this->numericStatistics[$column] = [
            'min' => ! empty($numericValues) ? min($numericValues) : 0,
            'max' => ! empty($numericValues) ? max($numericValues) : 0,
            'avg' => array_sum($numericValues) / count($numericValues),
        ];
    }

    /**
     * Calculate statistical measures for text columns.
     *
     * Computes minimum, maximum, and average string lengths for text data.
     * Helps identify field sizing requirements and data consistency patterns.
     *
     * @param  string  $column  Column name for storing results
     * @param  array<mixed>  $values  Text values from the column sample
     *
     * @throws DivisionByZeroError If average calculation encounters empty value set
     */
    private function calculateTextStatistics(string $column, array $values): void
    {
        $lengths = array_map(
            fn (mixed $value): int => mb_strlen($this->scalarString($value)),
            $values,
        );

        $this->textStatistics[$column] = [
            'min' => ! empty($lengths) ? min($lengths) : 0,
            'max' => ! empty($lengths) ? max($lengths) : 0,
            'avg' => array_sum($lengths) / count($lengths),
        ];
    }

    /**
     * Analyze and detect date format patterns for a column.
     *
     * Examines date values to determine the most likely date format pattern.
     * Helps configure proper date parsing for import operations.
     *
     * @param  string  $column  Column name for storing format results
     * @param  array<mixed>  $values  Date values from the column sample
     */
    private function analyzeDateFormat(string $column, array $values): void
    {
        // Take first non-null value as sample
        $sample = $values[0] ?? '';

        // Detect format pattern
        $format = 'Y-m-d'; // Default
        $sampleString = $this->scalarString($sample);
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $sampleString) === 1) {
            $format = 'd/m/Y'; // or m/d/Y - would need more samples to determine
        } elseif (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $sampleString) === 1) {
            $format = 'Y/m/d';
        } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $sampleString) === 1) {
            $format = 'd-m-Y';
        }

        $this->dateFormatAnalysis[$column] = [
            'format' => $format,
            'sample' => $sampleString,
        ];
    }

    /**
     * Generate processing recommendations based on analysis results.
     *
     * Analyzes file characteristics, data quality, and structural issues
     * to provide actionable recommendations for optimizing CSV import
     * operations including memory management, validation, and error handling.
     */
    private function generateRecommendations(): void
    {
        // File size recommendations
        if ($this->fileSize > 100 * 1024 * 1024) { // > 100MB
            $this->recommendations[] = 'Large file detected - use chunked processing';
        }

        // Data quality recommendations
        if ($this->dataQuality === CSVDataQualityEnum::POOR) {
            $this->recommendations[] = 'Poor data quality - enable strict validation';
        }

        if ($this->inconsistentRowCount > 0) {
            $this->recommendations[] = 'Inconsistent row structure - review and clean data';
        }

        if ($this->emptyRowCount > $this->analyzedRowCount * 0.1) {
            $this->recommendations[] = 'Many empty rows - enable skip empty rows option';
        }

        // Encoding recommendations
        if ($this->encoding !== CSVEncodingEnum::UTF8) {
            $this->recommendations[] = "Non-UTF8 encoding detected ({$this->encoding->value}) - consider conversion";
        }

        // Duplicate recommendations
        $hasDuplicates = false;
        foreach ($this->duplicateAnalysis as $duplicates) {
            if ($duplicates > 0) {
                $hasDuplicates = true;
                break;
            }
        }
        if ($hasDuplicates) {
            $this->recommendations[] = 'Duplicate values detected - configure duplicate handling strategy';
        }

        // Memory recommendations
        $estimatedMemory = $this->estimateMemoryUsage();
        if ($estimatedMemory > 512 * 1024 * 1024) {
            $this->recommendations[] = 'High memory usage expected - increase memory limit or use streaming';
        }
    }

    /**
     * Estimate the memory required for processing this CSV file.
     *
     * Calculates approximate memory requirements based on file size,
     * row count, and average row size. Includes overhead estimation
     * for PHP data structures and processing buffers.
     *
     * @return int Estimated memory usage in bytes
     */
    private function estimateMemoryUsage(): int
    {
        // Rough estimate: average row size * row count * overhead factor
        $avgRowSize = $this->fileSize / max($this->rowCount, 1);

        return (int) ($avgRowSize * $this->rowCount * 2.5); // 2.5x overhead for PHP arrays
    }

    /**
     * Create the comprehensive analysis result with all findings and recommendations.
     *
     * Compiles all analysis data including file properties, structure analysis,
     * data quality assessment, column analysis, recommendations, and metadata
     * into a complete result object.
     *
     * @param  float  $analysisTime  Time taken to complete the analysis in seconds
     * @return CSVAnalysisResultData Complete analysis results with comprehensive data and recommendations
     *
     * @throws Exception If result creation fails or data compilation errors occur
     */
    private function createResult(float $analysisTime): CSVAnalysisResultData
    {
        $estimatedMemory = $this->estimateMemoryUsage();
        $requiresChunking = $estimatedMemory > 128 * 1024 * 1024 || $this->rowCount > 10000;
        $recommendedChunkSize = $requiresChunking ? min(1000, max(100, (int) ($this->rowCount / 100))) : 0;

        return CSVAnalysisResultData::from([
            'filePath' => $this->filePath,
            'fileSize' => $this->fileSize,
            'rowCount' => $this->rowCount,
            'columnCount' => $this->columnCount,
            'headers' => $this->headers,
            'detectedDelimiter' => $this->delimiter,
            'detectedEncoding' => $this->encoding,
            'hasBom' => $this->hasBom,
            'lineEnding' => $this->lineEnding,
            'dataQuality' => $this->dataQuality,
            'validityScore' => $this->validityScore,
            'emptyRowCount' => $this->emptyRowCount,
            'inconsistentRowCount' => $this->inconsistentRowCount,
            'columnAnalysis' => $this->columnAnalysis,
            'duplicateAnalysis' => $this->duplicateAnalysis,
            'numericStatistics' => $this->numericStatistics,
            'textStatistics' => $this->textStatistics,
            'dateFormatAnalysis' => $this->dateFormatAnalysis,
            'issues' => $this->issues,
            'recommendations' => $this->recommendations,
            'estimatedMemoryUsage' => $estimatedMemory,
            'requiresChunking' => $requiresChunking,
            'recommendedChunkSize' => $recommendedChunkSize,
            'analyzedAt' => Carbon::now(),
            'analysisTime' => $analysisTime,
            'sampleData' => array_slice($this->sampleData, 0, 10),
            'metadata' => [
                'analyzer_version' => '1.0.0',
                'sample_size' => self::SAMPLE_SIZE,
                'max_analysis_rows' => self::MAX_ANALYSIS_ROWS,
                'analyzed_rows' => $this->analyzedRowCount,
            ],
        ]);
    }

    /**
     * Analyze the source selected by a public entry point.
     */
    private function analyzeCurrentSource(float $startTime): CSVAnalysisResultData
    {
        try {
            $this->openFile();
            $this->detectFileProperties();
            $this->analyzeStructure();
            $this->analyzeDataQuality();
            $this->analyzeColumnTypes();
            $this->generateRecommendations();

            return $this->createResult(microtime(true) - $startTime);
        } finally {
            $this->closeFile();
        }
    }

    /**
     * Convert the detection sample into UTF-8 before dialect inspection.
     */
    private function convertSampleToUtf8(string $sample): string
    {
        $bomLength = $this->hasBom ? strlen($this->encoding->getBOM()) : 0;
        $content = $bomLength > 0 ? substr($sample, $bomLength) : $sample;
        $sourceEncoding = $this->encoding->getPhpEncoding();

        if ($sourceEncoding === 'UTF-8') {
            return $content;
        }

        $converted = mb_convert_encoding($content, 'UTF-8', $sourceEncoding);
        if ($converted === false) {
            throw new RuntimeException("Unable to inspect CSV input encoded as {$sourceEncoding}.");
        }

        return $converted;
    }

    /**
     * Reset or reopen the stream, strip its BOM, and decode reads to UTF-8.
     */
    private function prepareInputStream(): void
    {
        if (! is_resource($this->handle)) {
            throw new RuntimeException('File handle is not open');
        }

        $metadata = stream_get_meta_data($this->handle);
        if ($metadata['seekable']) {
            rewind($this->handle);
        } else {
            $this->closeFile();
            $this->openFile();
        }

        $handle = $this->handle;
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to prepare CSV input stream.');
        }

        $bomLength = $this->hasBom ? strlen($this->encoding->getBOM()) : 0;
        if ($bomLength > 0) {
            fread($handle, $bomLength);
        }

        $sourceEncoding = $this->encoding->getPhpEncoding();
        if ($sourceEncoding !== 'UTF-8') {
            $filter = stream_filter_append(
                $handle,
                "convert.iconv.{$sourceEncoding}/UTF-8",
                STREAM_FILTER_READ,
            );

            if ($filter === false) {
                throw new RuntimeException("Unable to convert CSV input from {$sourceEncoding} to UTF-8.");
            }
        }
    }

    /**
     * Reset all derived analysis state so one service instance is reusable.
     */
    private function resetState(): void
    {
        $this->closeFile();
        $this->filePath = '';
        $this->sourceDisk = null;
        $this->sourcePath = null;
        $this->fileSize = 0;
        $this->delimiter = CSVDelimiterEnum::COMMA;
        $this->encoding = CSVEncodingEnum::UTF8;
        $this->lineEnding = 'LF';
        $this->hasBom = false;
        $this->headers = [];
        $this->sampleData = [];
        $this->rowCount = 0;
        $this->analyzedRowCount = 0;
        $this->columnCount = 0;
        $this->emptyRowCount = 0;
        $this->inconsistentRowCount = 0;
        $this->columnAnalysis = [];
        $this->duplicateAnalysis = [];
        $this->numericStatistics = [];
        $this->textStatistics = [];
        $this->dateFormatAnalysis = [];
        $this->issues = [];
        $this->recommendations = [];
        $this->validityScore = 0;
        $this->dataQuality = CSVDataQualityEnum::POOR;
    }

    /**
     * Convert a scalar-like analyzed value to a stable string.
     */
    private function scalarString(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
