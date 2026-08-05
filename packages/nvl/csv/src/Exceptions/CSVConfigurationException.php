<?php

declare(strict_types=1);

namespace Nvl\Csv\Exceptions;

/**
 * Exception for CSV configuration errors
 */
final class CSVConfigurationException extends CSVException
{
    /**
     * Create exception for invalid delimiter
     */
    public static function invalidDelimiter(string $delimiter): self
    {
        return (new self("Invalid CSV delimiter: '{$delimiter}'"))
            ->withContext(['delimiter' => $delimiter]);
    }

    /**
     * Create exception for invalid enclosure
     */
    public static function invalidEnclosure(string $enclosure): self
    {
        return (new self("Invalid CSV enclosure: '{$enclosure}'"))
            ->withContext(['enclosure' => $enclosure]);
    }

    /**
     * Create exception for invalid chunk size
     */
    public static function invalidChunkSize(int $size): self
    {
        return (new self("Invalid chunk size: {$size}. Must not be negative"))
            ->withContext(['chunk_size' => $size]);
    }

    /**
     * Create exception for invalid processing mode
     */
    public static function invalidProcessingMode(string $mode): self
    {
        return (new self("Invalid processing mode: '{$mode}'"))
            ->withContext(['processing_mode' => $mode]);
    }

    /**
     * Create exception for missing required configuration
     */
    public static function missingConfiguration(string $key): self
    {
        return (new self("Missing required configuration: '{$key}'"))
            ->withContext(['missing_key' => $key]);
    }

    /**
     * Create exception for invalid disk configuration
     */
    public static function invalidDisk(string $disk): self
    {
        return (new self("Storage disk '{$disk}' does not exist"))
            ->withContext(['disk' => $disk]);
    }

    /**
     * Create exception for invalid memory limit
     */
    public static function invalidMemoryLimit(int $limit): self
    {
        return (new self("Invalid memory limit: {$limit} bytes"))
            ->withContext(['memory_limit' => $limit]);
    }
}
