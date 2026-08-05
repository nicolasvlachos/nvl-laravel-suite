<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Standardized CSV field delimiter options with character mappings.
 *
 * Provides type-safe delimiter selection for CSV parsing and generation.
 * Each enum case maps to its corresponding character and provides
 * helpful utilities for file handling and user interfaces.
 */
enum CSVDelimiterEnum: string
{
    // Most common CSV delimiter - RFC 4180 standard
    case COMMA = 'comma';           // Standard CSV format (,)

    // Alternative delimiters for special use cases
    case SEMICOLON = 'semicolon';   // European CSV standard (;)
    case TAB = 'tab';               // Tab-separated values - TSV format
    case PIPE = 'pipe';             // Pipe-delimited files (|)
    case COLON = 'colon';           // Colon-separated format (:)

    /**
     * Get the actual delimiter character for CSV parsing/generation.
     *
     * Returns the single character that represents this delimiter type.
     * Used by CSV parsers and generators for field separation.
     *
     * @return string Single character delimiter
     */
    public function getCharacter(): string
    {
        return match ($this) {
            self::COMMA => ',',        // Standard CSV delimiter
            self::SEMICOLON => ';',    // European CSV standard
            self::TAB => "\t",         // Tab character for TSV files
            self::PIPE => '|',         // Pipe character for databases
            self::COLON => ':',        // Colon for specialized formats
        };
    }

    /**
     * Get human-friendly display label for user interfaces.
     *
     * Provides descriptive text suitable for dropdowns, forms,
     * and other UI elements where users select delimiter types.
     *
     * @return string User-friendly delimiter description
     */
    public function label(): string
    {
        return match ($this) {
            self::COMMA => 'Comma (,)',         // Most common format
            self::SEMICOLON => 'Semicolon (;)', // European preference
            self::TAB => 'Tab',                 // Clear spacing
            self::PIPE => 'Pipe (|)',           // Database exports
            self::COLON => 'Colon (:)',         // Specialized data
        };
    }

    /**
     * Get the standard file extension for this delimiter type.
     *
     * Most delimiters use 'csv' extension, but some have specific
     * conventions (like 'tsv' for tab-separated files).
     *
     * @return string File extension without the dot
     */
    public function getFileExtension(): string
    {
        return match ($this) {
            self::TAB => 'tsv',        // Tab-separated values have their own extension
            default => 'csv',          // All other delimiters use standard CSV extension
        };
    }

    /**
     * Find delimiter enum from actual character.
     *
     * Reverse lookup to find the appropriate enum case from
     * a delimiter character. Useful when parsing CSV files
     * with unknown or auto-detected delimiters.
     *
     * @param  string  $character  The delimiter character to match
     * @return self|null Matching enum case or null if not found
     */
    public static function fromCharacter(string $character): ?self
    {
        // Check each delimiter case for character match
        foreach (self::cases() as $case) {
            if ($case->getCharacter() === $character) {
                return $case;  // Found matching delimiter
            }
        }

        return null;  // No delimiter matches this character
    }
}
