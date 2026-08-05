<?php

declare(strict_types=1);

namespace Nvl\Csv\Data;

use Carbon\Carbon;
use Nvl\Csv\Enums\CSVDataQualityEnum;
use Nvl\Csv\Enums\CSVDelimiterEnum;
use Nvl\Csv\Enums\CSVEncodingEnum;
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

/**
 * CSV file analysis results with comprehensive metrics and recommendations.
 *
 * Provides detailed insights into CSV file structure, data quality,
 * and optimization recommendations for import/export operations.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class CSVAnalysisResultData extends Data
{
    use DataTransform;

    /**
     * Create analysis result data.
     *
     * @param  string  $filePath  Path to analyzed file
     * @param  int  $fileSize  File size in bytes
     * @param  int  $rowCount  Total number of rows
     * @param  int  $columnCount  Number of columns
     * @param  array<string>  $headers  Column headers if present
     * @param  CSVDelimiterEnum  $detectedDelimiter  Detected delimiter
     * @param  CSVEncodingEnum  $detectedEncoding  Detected character encoding
     * @param  bool  $hasBom  Whether file has BOM
     * @param  string  $lineEnding  Detected line ending (LF, CRLF, CR)
     * @param  CSVDataQualityEnum  $dataQuality  Overall data quality assessment
     * @param  float  $validityScore  Data validity percentage (0-100)
     * @param  int  $emptyRowCount  Number of empty rows
     * @param  int  $inconsistentRowCount  Rows with inconsistent column count
     * @param  array<string, array{type: string, nullable: bool, sample: mixed}>  $columnAnalysis  Column type analysis
     * @param  array<string, int>  $duplicateAnalysis  Duplicate value counts by column
     * @param  array<string, array{min: mixed, max: mixed, avg: mixed}>  $numericStatistics  Statistics for numeric columns
     * @param  array<string, array{min: int, max: int, avg: float}>  $textStatistics  Length statistics for text columns
     * @param  array<string, array{format: string, sample: string}>  $dateFormatAnalysis  Detected date formats by column
     * @param  list<array{severity: string, message: string, details?: mixed}>  $issues
     * @param  array<string>  $recommendations  Processing recommendations
     * @param  int  $estimatedMemoryUsage  Estimated memory for full load (bytes)
     * @param  bool  $requiresChunking  Whether file should be chunked
     * @param  int  $recommendedChunkSize  Optimal chunk size for processing
     * @param  Carbon  $analyzedAt  When analysis was performed
     * @param  float  $analysisTime  Time taken for analysis (seconds)
     * @param  list<array<int, mixed>>|Optional  $sampleData  Sample rows from file
     * @param  array<string, mixed>|Optional  $metadata  Additional analysis metadata
     * @return void
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $filePath,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $fileSize,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $rowCount,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $columnCount,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array $headers,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVDelimiterEnum::class)]
        public readonly CSVDelimiterEnum $detectedDelimiter,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVEncodingEnum::class)]
        public readonly CSVEncodingEnum $detectedEncoding,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $hasBom,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $lineEnding,
        #[TypeScriptOptional]
        #[TypeScriptType(CSVDataQualityEnum::class)]
        public readonly CSVDataQualityEnum $dataQuality,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly float $validityScore,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $emptyRowCount,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $inconsistentRowCount,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $columnAnalysis,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, number>')]
        public readonly array $duplicateAnalysis,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $numericStatistics,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $textStatistics,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $dateFormatAnalysis,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $issues,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[]')]
        public readonly array $recommendations,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $estimatedMemoryUsage,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $requiresChunking,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $recommendedChunkSize,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly Carbon $analyzedAt,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly float $analysisTime,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Array<Record<string, unknown>>')]
        public readonly array|Optional $sampleData,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $metadata,
    ) {}

    /**
     * Check if file is valid for processing.
     *
     * @return bool True when file is valid
     */
    public function isValid(): bool
    {
        return $this->validityScore >= 80.0 &&
               $this->rowCount > 0 &&
               $this->columnCount > 0;
    }

    /**
     * Check if file has data quality issues.
     *
     * @return bool True when file has issues
     */
    public function hasIssues(): bool
    {
        return ! empty($this->issues) ||
               in_array($this->dataQuality, [
                   CSVDataQualityEnum::POOR,
                   CSVDataQualityEnum::CRITICAL,
               ], true);
    }

    /**
     * Check if file structure is consistent.
     *
     * @return bool True when structure is consistent
     */
    public function isConsistent(): bool
    {
        return $this->inconsistentRowCount === 0 &&
               $this->validityScore >= 95.0;
    }

    /**
     * Get human-readable file size.
     *
     * @return string Formatted file size
     */
    public function getFileSizeFormatted(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->fileSize;

        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get estimated processing time based on file size and complexity.
     *
     * @return float Estimated processing time in seconds
     */
    public function getEstimatedProcessingTime(): float
    {
        // Base calculation: 10k rows per second for simple data
        $baseRate = 10000;

        // Adjust based on data quality
        $qualityMultiplier = match ($this->dataQuality) {
            CSVDataQualityEnum::EXCELLENT => 1.0,
            CSVDataQualityEnum::GOOD => 0.8,
            CSVDataQualityEnum::FAIR => 0.6,
            CSVDataQualityEnum::POOR => 0.4,
            CSVDataQualityEnum::CRITICAL => 0.2,
        };

        // Adjust based on column count
        $columnMultiplier = 1.0 - (($this->columnCount - 10) * 0.02);
        $columnMultiplier = max(0.3, min(1.0, $columnMultiplier));

        $effectiveRate = $baseRate * $qualityMultiplier * $columnMultiplier;

        return round($this->rowCount / $effectiveRate, 2);
    }

    /**
     * Get column types summary.
     *
     * @return array<string, int> Column type counts
     */
    public function getColumnTypesSummary(): array
    {
        $summary = [
            'text' => 0,
            'numeric' => 0,
            'date' => 0,
            'boolean' => 0,
            'mixed' => 0,
            'unknown' => 0,
        ];

        foreach ($this->columnAnalysis as $column) {
            $type = $column['type'];
            if (isset($summary[$type])) {
                $summary[$type]++;
            } else {
                $summary['unknown']++;
            }
        }

        return $summary;
    }

    /**
     * Get import configuration suggestions.
     *
     * @return array<string, mixed> Suggested import config
     */
    public function getSuggestedImportConfig(): array
    {
        return [
            'delimiter' => $this->detectedDelimiter->value,
            'encoding' => $this->detectedEncoding->value,
            'has_headers' => ! empty($this->headers),
            'chunk_size' => $this->requiresChunking ? $this->recommendedChunkSize : null,
            'skip_empty_rows' => $this->emptyRowCount > 0,
            'validate_data' => in_array($this->dataQuality, [
                CSVDataQualityEnum::POOR,
                CSVDataQualityEnum::CRITICAL,
            ], true),
            'strict_mode' => $this->dataQuality === CSVDataQualityEnum::POOR,
        ];
    }

    /**
     * Get critical issues that must be addressed.
     *
     * @return list<array{severity: string, message: string, details?: mixed}>
     */
    public function getCriticalIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (array $issue): bool => $issue['severity'] === 'critical',
        ));
    }

    /**
     * Get warnings that should be reviewed.
     *
     * @return list<array{severity: string, message: string, details?: mixed}>
     */
    public function getWarnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (array $issue): bool => $issue['severity'] === 'warning',
        ));
    }

    /**
     * Check if file has duplicate data.
     *
     * @return bool True when duplicates exist
     */
    public function hasDuplicates(): bool
    {
        foreach ($this->duplicateAnalysis as $duplicates) {
            if ($duplicates > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get memory usage recommendation.
     *
     * @return string Recommendation label
     */
    public function getMemoryRecommendation(): string
    {
        $requiredMemory = $this->estimatedMemoryUsage * 1.5; // 50% buffer

        if ($requiredMemory < 128 * 1024 * 1024) {
            return 'Standard memory allocation sufficient';
        } elseif ($requiredMemory < 512 * 1024 * 1024) {
            return 'Consider increasing memory limit to 512MB';
        } elseif ($requiredMemory < 1024 * 1024 * 1024) {
            return 'Large file - increase memory limit to 1GB or use chunking';
        } else {
            return 'Very large file - chunked processing required';
        }
    }

    /**
     * Get processing strategy recommendation.
     *
     * @return string Strategy identifier
     */
    public function getProcessingStrategy(): string
    {
        if ($this->rowCount < 1000) {
            return 'memory';
        } elseif ($this->rowCount < 10000 && $this->estimatedMemoryUsage < 128 * 1024 * 1024) {
            return 'memory';
        } elseif ($this->rowCount < 100000) {
            return 'chunked';
        } else {
            return 'streamed';
        }
    }

    /**
     * Convert to summary array for reporting.
     *
     * @return array<string, mixed> Summary payload
     */
    public function toSummary(): array
    {
        return [
            'file' => basename($this->filePath),
            'size' => $this->getFileSizeFormatted(),
            'rows' => number_format($this->rowCount),
            'columns' => $this->columnCount,
            'quality' => $this->dataQuality->label(),
            'validity' => $this->validityScore.'%',
            'encoding' => $this->detectedEncoding->value,
            'delimiter' => $this->detectedDelimiter->label(),
            'issues' => count($this->issues),
            'requires_chunking' => $this->requiresChunking,
            'estimated_time' => $this->getEstimatedProcessingTime().' seconds',
        ];
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
            'fileSize' => ['required', 'integer', 'min:0'],
            'rowCount' => ['required', 'integer', 'min:0'],
            'columnCount' => ['required', 'integer', 'min:0'],
            'headers' => ['required', 'array'],
            'detectedDelimiter' => ['required', 'string'],
            'detectedEncoding' => ['required', 'string'],
            'hasBom' => ['required', 'boolean'],
            'lineEnding' => ['required', 'string', 'in:LF,CRLF,CR'],
            'dataQuality' => ['required', 'string'],
            'validityScore' => ['required', 'numeric', 'min:0', 'max:100'],
            'emptyRowCount' => ['required', 'integer', 'min:0'],
            'inconsistentRowCount' => ['required', 'integer', 'min:0'],
            'columnAnalysis' => ['required', 'array'],
            'duplicateAnalysis' => ['required', 'array'],
            'numericStatistics' => ['required', 'array'],
            'textStatistics' => ['required', 'array'],
            'dateFormatAnalysis' => ['required', 'array'],
            'issues' => ['required', 'array'],
            'recommendations' => ['required', 'array'],
            'estimatedMemoryUsage' => ['required', 'integer', 'min:0'],
            'requiresChunking' => ['required', 'boolean'],
            'recommendedChunkSize' => ['required', 'integer', 'min:0'],
            'analyzedAt' => ['required', 'date'],
            'analysisTime' => ['required', 'numeric', 'min:0'],
            'sampleData' => ['nullable', 'array'],
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
