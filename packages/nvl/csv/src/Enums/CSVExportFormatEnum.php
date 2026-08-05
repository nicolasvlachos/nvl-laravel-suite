<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Predefined CSV export format configurations for cross-platform compatibility.
 *
 * Each format preset includes optimized settings for specific applications and platforms:
 * - Character encodings and BOM handling
 * - Platform-specific line endings
 * - Application-compatible delimiter/enclosure patterns
 */
enum CSVExportFormatEnum: string
{
    // Universal formats
    case STANDARD = 'standard';     // General-purpose CSV format
    case RFC4180 = 'rfc4180';       // Official RFC 4180 standard

    // Platform-specific optimizations
    case EXCEL = 'excel';           // Microsoft Excel (Windows) - includes BOM
    case EXCEL_MAC = 'excel_mac';   // Microsoft Excel (macOS) - Mac line endings
    case UNIX = 'unix';             // Unix/Linux systems - LF line endings

    // Alternative formats
    case TSV = 'tsv';               // Tab-separated values

    /**
     * Get complete configuration settings for this export format.
     *
     * Returns all necessary parameters for CSV generation:
     * - delimiter: Field separator character
     * - enclosure: Quote character for fields containing delimiters
     * - escape: Character used to escape special characters
     * - line_ending: Platform-appropriate line terminator
     * - include_bom: Whether to include UTF-8 Byte Order Mark
     *
     * @return array{delimiter: string, enclosure: string, escape: string, line_ending: string, include_bom: bool}
     */
    public function getSettings(): array
    {
        return match ($this) {
            self::STANDARD => [
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '\\',
                'line_ending' => "\n",
                'include_bom' => false,
            ],
            self::EXCEL => [
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '"',
                'line_ending' => "\r\n",
                'include_bom' => true,
            ],
            self::EXCEL_MAC => [
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '"',
                'line_ending' => "\r",
                'include_bom' => true,
            ],
            self::RFC4180 => [
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '"',
                'line_ending' => "\r\n",
                'include_bom' => false,
            ],
            self::TSV => [
                'delimiter' => "\t",
                'enclosure' => '"',
                'escape' => '\\',
                'line_ending' => "\n",
                'include_bom' => false,
            ],
            self::UNIX => [
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '\\',
                'line_ending' => "\n",
                'include_bom' => false,
            ],
        };
    }

    /**
     * Get user-friendly display name for this format.
     *
     * Suitable for dropdowns, form labels, and other UI elements
     * where users select export formats.
     *
     * @return string Human-readable format name
     */
    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard CSV',
            self::EXCEL => 'Excel (Windows)',
            self::EXCEL_MAC => 'Excel (Mac)',
            self::RFC4180 => 'RFC 4180 Standard',
            self::TSV => 'Tab-Separated Values',
            self::UNIX => 'Unix/Linux',
        };
    }

    /**
     * Get detailed explanation of this format and its use cases.
     *
     * Provides context about when and why to use each format,
     * including compatibility information and platform specifics.
     *
     * @return string Detailed format description
     */
    public function description(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard CSV format compatible with most applications',
            self::EXCEL => 'Optimized for Microsoft Excel on Windows with UTF-8 BOM',
            self::EXCEL_MAC => 'Optimized for Microsoft Excel on macOS',
            self::RFC4180 => 'Follows RFC 4180 standard for CSV files',
            self::TSV => 'Tab-separated values format',
            self::UNIX => 'Unix/Linux compatible format with LF line endings',
        };
    }

    /**
     * Get the recommended file extension for this export format.
     *
     * Most formats use 'csv' extension, but tab-separated values
     * conventionally use 'tsv' for better file type recognition.
     *
     * @return string File extension without the leading dot
     */
    public function getFileExtension(): string
    {
        return match ($this) {
            self::TSV => 'tsv',
            default => 'csv',
        };
    }

    /**
     * Check if this format should include UTF-8 Byte Order Mark (BOM).
     *
     * BOM helps applications (especially Microsoft Excel) correctly
     * detect UTF-8 encoding, preventing character encoding issues.
     * Generally recommended for Excel compatibility.
     *
     * @return bool True if BOM should be included in output
     */
    public function includeBOM(): bool
    {
        return $this->getSettings()['include_bom'];
    }

    /**
     * Get the field delimiter character for this format.
     *
     * Returns the single character used to separate fields in CSV data.
     * Most formats use comma, but TSV uses tab character.
     *
     * @return string Single character delimiter
     */
    public function getDelimiter(): string
    {
        return $this->getSettings()['delimiter'];
    }

    /**
     * Get the field enclosure character for this format.
     *
     * Returns the character used to quote fields that contain
     * special characters (delimiters, line breaks, quotes).
     * Standard is double quote (") for all formats.
     *
     * @return string Single character enclosure
     */
    public function getEnclosure(): string
    {
        return $this->getSettings()['enclosure'];
    }

    /**
     * Get the escape character for this format.
     *
     * Returns the character used to escape special characters
     * within quoted fields. Excel uses quote doubling (""),
     * while standard formats often use backslash escaping.
     *
     * @return string Single character escape
     */
    public function getEscape(): string
    {
        return $this->getSettings()['escape'];
    }

    /**
     * Get the line ending sequence for this format.
     *
     * Returns the character sequence used to terminate each record.
     * Platform-specific endings ensure compatibility:
     * - Windows: \r\n (CRLF)
     * - Unix/Linux: \n (LF)
     * - Classic Mac: \r (CR)
     *
     * @return string Line ending sequence
     */
    public function getLineEnding(): string
    {
        return $this->getSettings()['line_ending'];
    }
}
