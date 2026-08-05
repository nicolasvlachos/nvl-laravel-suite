<?php

declare(strict_types=1);

namespace Nvl\Csv\Exceptions;

/**
 * Exception for CSV memory-related errors
 */
final class CSVMemoryException extends CSVException
{
    /**
     * Create exception for memory limit exceeded
     */
    public static function memoryLimitExceeded(int $current, int $limit): self
    {
        $currentMb = round($current / 1024 / 1024, 2);
        $limitMb = round($limit / 1024 / 1024, 2);

        return (new self("Memory limit exceeded: {$currentMb}MB used, {$limitMb}MB limit"))
            ->withContext([
                'current_bytes' => $current,
                'limit_bytes' => $limit,
                'current_mb' => $currentMb,
                'limit_mb' => $limitMb,
            ]);
    }

    /**
     * Create exception for file too large
     */
    public static function fileTooLarge(string $path, int $size, int $maxSize): self
    {
        $sizeMb = round($size / 1024 / 1024, 2);
        $maxSizeMb = round($maxSize / 1024 / 1024, 2);

        return (new self("File '{$path}' is too large: {$sizeMb}MB, maximum allowed: {$maxSizeMb}MB"))
            ->withContext([
                'file_path' => $path,
                'file_size' => $size,
                'max_size' => $maxSize,
                'size_mb' => $sizeMb,
                'max_size_mb' => $maxSizeMb,
            ]);
    }

    /**
     * Create exception for insufficient memory
     */
    public static function insufficientMemory(int $required, int $available): self
    {
        $requiredMb = round($required / 1024 / 1024, 2);
        $availableMb = round($available / 1024 / 1024, 2);

        return (new self("Insufficient memory: {$requiredMb}MB required, {$availableMb}MB available"))
            ->withContext([
                'required_bytes' => $required,
                'available_bytes' => $available,
                'required_mb' => $requiredMb,
                'available_mb' => $availableMb,
            ]);
    }

    /**
     * Create exception for chunk size too large
     */
    public static function chunkSizeTooLarge(int $chunkSize, int $recommendedSize): self
    {
        return (new self("Chunk size {$chunkSize} is too large for available memory. Recommended: {$recommendedSize}"))
            ->withContext([
                'chunk_size' => $chunkSize,
                'recommended_size' => $recommendedSize,
            ]);
    }

    /**
     * Create exception for memory allocation failure
     */
    public static function allocationFailed(string $operation): self
    {
        return (new self("Failed to allocate memory for operation: {$operation}"))
            ->withContext([
                'operation' => $operation,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);
    }
}
