<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Character encoding enumeration for CSV file processing.
 *
 * Provides comprehensive encoding support for international CSV files:
 * - UTF variants with and without BOM (Byte Order Mark)
 * - Legacy encodings for backward compatibility
 * - Asian language encodings (Japanese, Chinese, Korean)
 * - Windows and ISO encodings for European languages
 */
enum CSVEncodingEnum: string
{
    // Unicode encodings - most common and recommended
    case UTF8 = 'UTF-8';                 // Standard UTF-8 without BOM
    case UTF8_BOM = 'UTF-8-BOM';         // UTF-8 with BOM for Excel
    case UTF16 = 'UTF-16';               // UTF-16 with auto-detection
    case UTF16_LE = 'UTF-16LE';          // UTF-16 Little Endian
    case UTF16_BE = 'UTF-16BE';          // UTF-16 Big Endian
    case UTF32 = 'UTF-32';               // UTF-32 (rare but supported)
    case UTF32_LE = 'UTF-32LE';           // UTF-32 Little Endian
    case UTF32_BE = 'UTF-32BE';           // UTF-32 Big Endian

    // Legacy ASCII-based encodings
    case ASCII = 'ASCII';                // 7-bit ASCII
    case ISO_8859_1 = 'ISO-8859-1';      // Latin-1 (Western European)
    case ISO_8859_2 = 'ISO-8859-2';      // Latin-2 (Central European)
    case ISO_8859_15 = 'ISO-8859-15';    // Latin-9 (Western European with Euro)

    // Windows code pages
    case WINDOWS_1250 = 'Windows-1250';  // Central European
    case WINDOWS_1251 = 'Windows-1251';  // Cyrillic
    case WINDOWS_1252 = 'Windows-1252';  // Western European
    case WINDOWS_1254 = 'Windows-1254';  // Turkish
    case WINDOWS_1257 = 'Windows-1257';  // Baltic

    // Asian encodings
    case SHIFT_JIS = 'Shift_JIS';        // Japanese
    case EUC_JP = 'EUC-JP';              // Japanese Unix
    case ISO_2022_JP = 'ISO-2022-JP';    // Japanese email
    case GB2312 = 'GB2312';              // Simplified Chinese
    case GBK = 'GBK';                    // Extended Chinese
    case BIG5 = 'Big5';                  // Traditional Chinese
    case EUC_KR = 'EUC-KR';              // Korean

    /**
     * Get the PHP iconv/mbstring encoding identifier.
     *
     * Returns the encoding string used by PHP's multi-byte string
     * and iconv functions for character conversion operations.
     *
     * @return string PHP encoding identifier
     */
    public function getPhpEncoding(): string
    {
        return match ($this) {
            self::UTF8_BOM => 'UTF-8', // BOM is handled separately
            default => $this->value,
        };
    }

    /**
     * Check if this encoding uses a Byte Order Mark (BOM).
     *
     * BOM helps applications detect encoding, especially important
     * for Excel compatibility with UTF-8 files.
     *
     * @return bool True if encoding includes BOM
     */
    public function hasBOM(): bool
    {
        return match ($this) {
            self::UTF8_BOM => true,
            self::UTF16, self::UTF16_LE, self::UTF16_BE,
            self::UTF32, self::UTF32_LE, self::UTF32_BE => true,
            default => false,
        };
    }

    /**
     * Get the BOM bytes for this encoding.
     *
     * Returns the actual byte sequence used as BOM for encodings
     * that support it. Returns empty string for non-BOM encodings.
     *
     * @return string BOM byte sequence
     */
    public function getBOM(): string
    {
        return match ($this) {
            self::UTF8_BOM => "\xEF\xBB\xBF",           // UTF-8 BOM
            self::UTF16, self::UTF16_LE => "\xFF\xFE",  // UTF-16 LE BOM
            self::UTF16_BE => "\xFE\xFF",               // UTF-16 BE BOM
            self::UTF32, self::UTF32_BE => "\x00\x00\xFE\xFF",
            self::UTF32_LE => "\xFF\xFE\x00\x00",
            default => '',
        };
    }

    /**
     * Check if conversion to another encoding is supported.
     *
     * Determines if this encoding can be safely converted to
     * another encoding without data loss (for compatible character sets).
     *
     * @param  string  $targetEncoding  Target encoding name
     * @return bool True if conversion is supported
     */
    public function canConvert(string $targetEncoding): bool
    {
        // UTF encodings can convert to anything
        if (str_starts_with($this->value, 'UTF')) {
            return true;
        }

        // ASCII can convert to any superset
        if ($this === self::ASCII) {
            return true;
        }

        // Check if target is UTF (can accept anything)
        if (str_starts_with($targetEncoding, 'UTF')) {
            return true;
        }

        // Otherwise, only same encoding family
        return $this->getEncodingFamily() === self::getFamily($targetEncoding);
    }

    /**
     * Get the encoding family/group for compatibility checking.
     *
     * Groups encodings by their character set compatibility
     * for safe conversion operations.
     *
     * @return string Encoding family identifier
     */
    public function getEncodingFamily(): string
    {
        return match ($this) {
            self::UTF8, self::UTF8_BOM, self::UTF16,
            self::UTF16_LE, self::UTF16_BE, self::UTF32,
            self::UTF32_LE, self::UTF32_BE => 'unicode',

            self::ASCII => 'ascii',

            self::ISO_8859_1, self::ISO_8859_2,
            self::ISO_8859_15 => 'iso-latin',

            self::WINDOWS_1250, self::WINDOWS_1251,
            self::WINDOWS_1252, self::WINDOWS_1254,
            self::WINDOWS_1257 => 'windows',

            self::SHIFT_JIS, self::EUC_JP,
            self::ISO_2022_JP => 'japanese',

            self::GB2312, self::GBK, self::BIG5 => 'chinese',

            self::EUC_KR => 'korean',
        };
    }

    /**
     * Get user-friendly display name for this encoding.
     *
     * Provides human-readable names suitable for UI dropdowns
     * and user-facing interfaces.
     *
     * @return string Display name with description
     */
    public function label(): string
    {
        return match ($this) {
            self::UTF8 => 'UTF-8',
            self::UTF8_BOM => 'UTF-8 with BOM (Excel)',
            self::UTF16 => 'UTF-16',
            self::UTF16_LE => 'UTF-16 Little Endian',
            self::UTF16_BE => 'UTF-16 Big Endian',
            self::UTF32 => 'UTF-32',
            self::UTF32_LE => 'UTF-32 Little Endian',
            self::UTF32_BE => 'UTF-32 Big Endian',
            self::ASCII => 'ASCII (7-bit)',
            self::ISO_8859_1 => 'ISO-8859-1 (Latin-1)',
            self::ISO_8859_2 => 'ISO-8859-2 (Latin-2)',
            self::ISO_8859_15 => 'ISO-8859-15 (Latin-9 with Euro)',
            self::WINDOWS_1250 => 'Windows-1250 (Central European)',
            self::WINDOWS_1251 => 'Windows-1251 (Cyrillic)',
            self::WINDOWS_1252 => 'Windows-1252 (Western European)',
            self::WINDOWS_1254 => 'Windows-1254 (Turkish)',
            self::WINDOWS_1257 => 'Windows-1257 (Baltic)',
            self::SHIFT_JIS => 'Shift-JIS (Japanese)',
            self::EUC_JP => 'EUC-JP (Japanese Unix)',
            self::ISO_2022_JP => 'ISO-2022-JP (Japanese Email)',
            self::GB2312 => 'GB2312 (Simplified Chinese)',
            self::GBK => 'GBK (Extended Chinese)',
            self::BIG5 => 'Big5 (Traditional Chinese)',
            self::EUC_KR => 'EUC-KR (Korean)',
        };
    }

    /**
     * Detect encoding from BOM bytes.
     *
     * Attempts to identify encoding based on Byte Order Mark
     * at the beginning of a file.
     *
     * @param  string  $bytes  First few bytes of file
     * @return self|null Detected encoding or null if no BOM
     */
    public static function detectFromBOM(string $bytes): ?self
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return self::UTF8_BOM;
        }

        if (str_starts_with($bytes, "\xFF\xFE\x00\x00")) {
            return self::UTF32_LE;
        }

        if (str_starts_with($bytes, "\x00\x00\xFE\xFF")) {
            return self::UTF32;
        }

        if (str_starts_with($bytes, "\xFF\xFE")) {
            return self::UTF16_LE;
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return self::UTF16_BE;
        }

        return null;
    }

    /**
     * Get recommended encoding for Excel compatibility.
     *
     * Returns the best encoding for Microsoft Excel based on
     * the platform and Excel version.
     *
     * @param  bool  $windows  Whether targeting Windows Excel
     * @return self Recommended encoding
     */
    public static function excelRecommended(bool $windows = true): self
    {
        return $windows ? self::UTF8_BOM : self::UTF8;
    }

    /**
     * Get encoding family for a string encoding name.
     *
     * Helper method to determine encoding family from string.
     *
     * @param  string  $encoding  Encoding name
     * @return string Family identifier
     */
    private static function getFamily(string $encoding): string
    {
        return match (true) {
            str_starts_with($encoding, 'UTF') => 'unicode',
            $encoding === 'ASCII' => 'ascii',
            str_starts_with($encoding, 'ISO-8859') => 'iso-latin',
            str_starts_with($encoding, 'Windows') => 'windows',
            default => 'unknown',
        };
    }
}
