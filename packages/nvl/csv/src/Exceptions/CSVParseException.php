<?php

declare(strict_types=1);

namespace Nvl\Csv\Exceptions;

/**
 * Exception for CSV parsing errors
 */
final class CSVParseException extends CSVException
{
    /**
     * Create exception for invalid CSV structure
     */
    public static function invalidStructure(string $reason, ?int $line = null): self
    {
        $message = $line !== null
            ? "Invalid CSV structure at line {$line}: {$reason}"
            : "Invalid CSV structure: {$reason}";

        return (new self($message))
            ->withContext([
                'reason' => $reason,
                'line' => $line,
            ]);
    }

    /**
     * Create exception for header mismatch
     *
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    public static function headerMismatch(array $expected, array $actual): self
    {
        return (new self('CSV headers do not match expected structure'))
            ->withContext([
                'expected' => $expected,
                'actual' => $actual,
                'missing' => array_diff($expected, $actual),
                'extra' => array_diff($actual, $expected),
            ]);
    }

    /**
     * Create exception for column count mismatch
     */
    public static function columnCountMismatch(int $expected, int $actual, int $line): self
    {
        return (new self("Column count mismatch at line {$line}: expected {$expected}, got {$actual}"))
            ->withContext([
                'expected_count' => $expected,
                'actual_count' => $actual,
                'line' => $line,
            ]);
    }

    /**
     * Create exception for invalid encoding
     */
    public static function invalidEncoding(string $encoding, ?int $line = null): self
    {
        $message = $line !== null
            ? "Invalid character encoding at line {$line}: expected {$encoding}"
            : "Invalid character encoding: expected {$encoding}";

        return (new self($message))
            ->withContext([
                'encoding' => $encoding,
                'line' => $line,
            ]);
    }

    /**
     * Create exception for missing required column
     */
    public static function missingRequiredColumn(string $column): self
    {
        return (new self("Required column '{$column}' is missing from CSV"))
            ->withContext(['column' => $column]);
    }

    /**
     * Create exception for invalid delimiter detection
     */
    public static function cannotDetectDelimiter(): self
    {
        return new self('Unable to automatically detect CSV delimiter');
    }

    /**
     * Create exception for parsing error
     */
    public static function parsingFailed(string $reason, ?int $line = null): self
    {
        $message = $line !== null
            ? "Failed to parse CSV at line {$line}: {$reason}"
            : "Failed to parse CSV: {$reason}";

        return (new self($message))
            ->withContext([
                'reason' => $reason,
                'line' => $line,
            ]);
    }

    /**
     * Create exception for invalid headers
     */
    public static function invalidHeaders(): self
    {
        return new self('CSV file has invalid or empty headers');
    }
}
