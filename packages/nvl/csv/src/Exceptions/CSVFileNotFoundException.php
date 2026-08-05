<?php

declare(strict_types=1);

namespace Nvl\Csv\Exceptions;

/**
 * Exception for CSV file not found errors
 */
final class CSVFileNotFoundException extends CSVException
{
    /**
     * Create exception for file not found
     */
    public static function fileNotFound(string $path): self
    {
        return (new self("CSV file not found: '{$path}'"))
            ->withContext(['file_path' => $path]);
    }

    /**
     * Create exception for file not found on disk
     */
    public static function fileNotFoundOnDisk(string $disk, string $path): self
    {
        return (new self("CSV file not found on disk '{$disk}': '{$path}'"))
            ->withContext(['disk' => $disk, 'file_path' => $path]);
    }

    /**
     * Create exception for directory not found
     */
    public static function directoryNotFound(string $path): self
    {
        return (new self("Directory not found: '{$path}'"))
            ->withContext(['directory_path' => $path]);
    }

    /**
     * Create exception for unreadable file
     */
    public static function fileNotReadable(string $path): self
    {
        return (new self("CSV file is not readable: '{$path}'"))
            ->withContext([
                'file_path' => $path,
                'exists' => file_exists($path),
                'readable' => is_readable($path),
            ]);
    }

    /**
     * Create exception for unwritable path
     */
    public static function pathNotWritable(string $path): self
    {
        return (new self("Path is not writable: '{$path}'"))
            ->withContext([
                'path' => $path,
                'exists' => file_exists($path),
                'writable' => is_writable($path),
            ]);
    }

    /**
     * Create exception for empty file
     */
    public static function fileEmpty(string $path): self
    {
        return (new self("CSV file is empty: '{$path}'"))
            ->withContext([
                'file_path' => $path,
                'size' => filesize($path),
            ]);
    }
}
