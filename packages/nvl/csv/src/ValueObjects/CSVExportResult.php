<?php

declare(strict_types=1);

namespace Nvl\Csv\ValueObjects;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Value object representing CSV export result.
 *
 * Contains all information about a completed CSV export operation,
 * including file path, statistics, and metadata.
 */
final readonly class CSVExportResult
{
    /**
     * Create a new CSV export result.
     *
     * @param  string  $path  Absolute path to exported file
     * @param  string|null  $url  Public URL to exported file (if available)
     * @param  string  $filename  Base filename of exported file
     * @param  int  $rowCount  Number of data rows exported
     * @param  int  $columnCount  Number of columns in export
     * @param  int  $fileSize  File size in bytes
     * @param  float  $processingTime  Processing time in seconds
     * @param  Carbon  $createdAt  When export was created
     * @param  string|null  $disk  Storage disk used
     * @param  string|null  $mimeType  MIME type of exported file
     * @param  array<string, mixed>  $metadata  Additional metadata
     * @param  array<int, string>  $errors  Export errors
     * @param  array<int, string>  $warnings  Export warnings
     */
    public function __construct(
        public string $path,
        public ?string $url,
        public string $filename,
        public int $rowCount,
        public int $columnCount,
        public int $fileSize,
        public float $processingTime,
        public Carbon $createdAt,
        public ?string $disk = null,
        public ?string $mimeType = 'text/csv',
        public array $metadata = [],
        public array $errors = [],
        public array $warnings = [],
    ) {}

    /**
     * Create from export data.
     *
     * @param  array{path: string, url?: string|null}  $fileData  File information
     * @param  array<string, mixed>  $stats  Export statistics
     */
    public static function fromExport(array $fileData, array $stats): self
    {
        return new self(
            path: $fileData['path'],
            url: $fileData['url'] ?? null,
            filename: basename($fileData['path']),
            rowCount: self::resolveInt($stats['row_count'] ?? null),
            columnCount: self::resolveInt($stats['column_count'] ?? null),
            fileSize: self::resolveFileSize($stats['file_size'] ?? null, $fileData['path']),
            processingTime: self::resolveFloat($stats['processing_time'] ?? null),
            createdAt: Carbon::now(),
            disk: self::resolveNullableString($stats['disk'] ?? null),
            mimeType: self::resolveNullableString($stats['mime_type'] ?? 'text/csv'),
            metadata: self::resolveMetadata($stats['metadata'] ?? null),
            errors: self::resolveStringList($stats['errors'] ?? null),
            warnings: self::resolveStringList($stats['warnings'] ?? null),
        );
    }

    private static function resolveInt(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function resolveFloat(mixed $value): float
    {
        return is_float($value) || is_int($value) ? (float) $value : 0.0;
    }

    private static function resolveFileSize(mixed $value, string $path): int
    {
        if (is_int($value)) {
            return $value;
        }

        $size = is_file($path) ? filesize($path) : false;

        return $size === false ? 0 : $size;
    }

    private static function resolveNullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $metadata = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $metadata[$key] = $item;
            }
        }

        return $metadata;
    }

    /**
     * @return list<string>
     */
    private static function resolveStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * Check if export was successful.
     *
     * @return bool True if no errors and at least one row was exported
     */
    public function isSuccessful(): bool
    {
        return empty($this->errors) && $this->rowCount > 0;
    }

    /**
     * Check if export has warnings.
     *
     * @return bool True if warnings exist
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * Check if export has errors.
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Get human-readable file size.
     *
     * @return string Formatted file size with units
     */
    public function getHumanFileSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->fileSize;

        $i = 0;
        for (; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get processing time in seconds.
     *
     * @return float Processing time rounded to 2 decimal places
     */
    public function getProcessingTimeInSeconds(): float
    {
        return round($this->processingTime, 2);
    }

    /**
     * Get rows per second processing rate.
     *
     * @return float Rows processed per second, 0 if processing time is 0
     */
    public function getRowsPerSecond(): float
    {
        if ($this->processingTime <= 0) {
            return 0;
        }

        return round($this->rowCount / $this->processingTime, 2);
    }

    /**
     * Check if exported file still exists on disk.
     *
     * @return bool True if file exists
     */
    public function fileExists(): bool
    {
        $storagePath = $this->metadata['storage_path'] ?? null;
        if ($this->disk !== null && is_string($storagePath)) {
            return Storage::disk($this->disk)->exists($storagePath);
        }

        return file_exists($this->path);
    }

    /**
     * Get download response array for API.
     *
     * @return array<string, mixed> API-friendly response data
     */
    public function toDownloadResponse(): array
    {
        return [
            'success' => $this->isSuccessful(),
            'filename' => $this->filename,
            'url' => $this->url,
            'size' => $this->getHumanFileSize(),
            'rows' => $this->rowCount,
            'processing_time' => $this->getProcessingTimeInSeconds().'s',
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed> Complete export result data
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'url' => $this->url,
            'filename' => $this->filename,
            'row_count' => $this->rowCount,
            'column_count' => $this->columnCount,
            'file_size' => $this->fileSize,
            'file_size_human' => $this->getHumanFileSize(),
            'processing_time' => $this->processingTime,
            'rows_per_second' => $this->getRowsPerSecond(),
            'created_at' => $this->createdAt->toIso8601String(),
            'disk' => $this->disk,
            'mime_type' => $this->mimeType,
            'metadata' => $this->metadata,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'successful' => $this->isSuccessful(),
        ];
    }
}
