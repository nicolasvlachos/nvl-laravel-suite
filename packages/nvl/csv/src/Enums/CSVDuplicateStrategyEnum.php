<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Duplicate handling strategies for CSV import operations.
 *
 * Defines how to handle duplicate records during CSV processing:
 * - Control duplicate detection and resolution
 * - Support various business rules for duplicates
 * - Enable flexible import strategies
 * - Maintain data integrity and consistency
 */
enum CSVDuplicateStrategyEnum: string
{
    // Duplicate handling strategies
    case SKIP = 'skip';             // Skip duplicate rows silently
    case UPDATE = 'update';         // Update existing records with new data
    case REPLACE = 'replace';       // Replace entire record with new data
    case CREATE = 'create';         // Create new record regardless
    case MERGE = 'merge';           // Merge new data with existing
    case INCREMENT = 'increment';   // Create with incremented identifier
    case ERROR = 'error';           // Throw error on duplicate
    case ARCHIVE = 'archive';       // Archive old, create new

    /**
     * Check if strategy requires checking for existing records.
     *
     * Some strategies need to query existing data before processing.
     *
     * @return bool True if existing record check is required
     */
    public function shouldCheckExisting(): bool
    {
        return match ($this) {
            self::CREATE => false, // Always create new
            default => true,       // All others need to check
        };
    }

    /**
     * Check if strategy modifies existing records.
     *
     * Determines if existing database records will be changed.
     *
     * @return bool True if existing records are modified
     */
    public function modifiesExisting(): bool
    {
        return match ($this) {
            self::UPDATE, self::REPLACE, self::MERGE, self::ARCHIVE => true,
            default => false,
        };
    }

    /**
     * Check if strategy creates new records.
     *
     * Determines if new database records will be created.
     *
     * @return bool True if new records are created
     */
    public function createsNew(): bool
    {
        return match ($this) {
            self::CREATE, self::INCREMENT, self::ARCHIVE => true,
            self::UPDATE, self::REPLACE, self::MERGE => false, // Only modifies
            self::SKIP => false,    // No action
            self::ERROR => false,   // Throws error
        };
    }

    /**
     * Check if strategy preserves existing data.
     *
     * Determines if original data is kept when duplicates are found.
     *
     * @return bool True if existing data is preserved
     */
    public function preservesExisting(): bool
    {
        return match ($this) {
            self::SKIP => true,      // Keeps existing completely
            self::MERGE => true,     // Preserves non-conflicting data
            self::UPDATE => true,    // Preserves non-updated fields
            self::ARCHIVE => true,   // Preserves in archive
            self::REPLACE => false,  // Overwrites completely
            self::CREATE, self::INCREMENT => true, // Doesn't touch existing
            self::ERROR => true,     // No changes on error
        };
    }

    /**
     * Check if strategy stops processing on duplicate.
     *
     * ERROR strategy halts import when duplicates are found.
     *
     * @return bool True if processing stops
     */
    public function stopsProcessing(): bool
    {
        return $this === self::ERROR;
    }

    /**
     * Get the merge strategy for combining data.
     *
     * Describes how new and existing data are combined.
     *
     * @return string Merge strategy description
     */
    public function getMergeStrategy(): string
    {
        return match ($this) {
            self::SKIP => 'keep_existing',        // Ignore new data
            self::UPDATE => 'new_overwrites',     // New values overwrite
            self::REPLACE => 'full_replacement',  // Complete replacement
            self::MERGE => 'fill_missing',        // Fill null/empty fields
            self::CREATE => 'separate_record',    // Independent records
            self::INCREMENT => 'unique_variant',  // Create variant
            self::ERROR => 'no_merge',           // No merging
            self::ARCHIVE => 'archive_and_new',  // Archive old, use new
        };
    }

    /**
     * Get SQL operation for this strategy.
     *
     * Returns the primary SQL operation used.
     *
     * @return string SQL operation type
     */
    public function getSqlOperation(): string
    {
        return match ($this) {
            self::SKIP => 'SELECT',           // Check only
            self::UPDATE => 'UPDATE',         // Update existing
            self::REPLACE => 'DELETE+INSERT', // Replace record
            self::CREATE => 'INSERT',         // Always insert
            self::MERGE => 'UPDATE',          // Selective update
            self::INCREMENT => 'INSERT',      // Insert with modification
            self::ERROR => 'SELECT',          // Check and error
            self::ARCHIVE => 'UPDATE+INSERT', // Archive and insert
        };
    }

    /**
     * Get priority for conflict resolution.
     *
     * Higher priority strategies take precedence in conflicts.
     *
     * @return int Priority level (1-10)
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::ERROR => 10,      // Highest - stops everything
            self::SKIP => 8,        // Preserve existing data
            self::ARCHIVE => 7,     // Preserve with history
            self::MERGE => 6,       // Intelligent combination
            self::UPDATE => 5,      // Partial update
            self::REPLACE => 4,     // Full replacement
            self::INCREMENT => 3,   // Create variant
            self::CREATE => 2,      // Always create
        };
    }

    /**
     * Check if strategy requires unique identifier.
     *
     * Some strategies need a unique key for duplicate detection.
     *
     * @return bool True if unique identifier is required
     */
    public function requiresUniqueIdentifier(): bool
    {
        return match ($this) {
            self::CREATE => false, // No checking needed
            default => true,       // All others need identifier
        };
    }

    /**
     * Get performance impact level.
     *
     * Indicates relative performance cost of this strategy.
     *
     * @return string Performance impact level
     */
    public function getPerformanceImpact(): string
    {
        return match ($this) {
            self::SKIP => 'low',       // Simple check
            self::CREATE => 'minimal', // No checking
            self::UPDATE => 'medium',  // Update queries
            self::MERGE => 'high',     // Complex logic
            self::REPLACE => 'high',   // Delete + Insert
            self::INCREMENT => 'medium', // Identifier generation
            self::ERROR => 'low',      // Just checking
            self::ARCHIVE => 'very_high', // Multiple operations
        };
    }

    /**
     * Get user-friendly label for this strategy.
     *
     * Provides human-readable labels for UI display.
     *
     * @return string Display label
     */
    public function label(): string
    {
        return match ($this) {
            self::SKIP => 'Skip Duplicates',
            self::UPDATE => 'Update Existing',
            self::REPLACE => 'Replace Existing',
            self::CREATE => 'Always Create New',
            self::MERGE => 'Merge Data',
            self::INCREMENT => 'Create with New ID',
            self::ERROR => 'Error on Duplicate',
            self::ARCHIVE => 'Archive and Replace',
        };
    }

    /**
     * Get detailed description of this strategy.
     *
     * Explains how duplicates are handled with this strategy.
     *
     * @return string Strategy description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::SKIP => 'Skip duplicate rows without importing them',
            self::UPDATE => 'Update existing records with new values',
            self::REPLACE => 'Completely replace existing records',
            self::CREATE => 'Create new records regardless of duplicates',
            self::MERGE => 'Intelligently merge new data with existing',
            self::INCREMENT => 'Create new record with incremented identifier',
            self::ERROR => 'Stop import and report error on duplicates',
            self::ARCHIVE => 'Archive existing record and create new one',
        };
    }

    /**
     * Get icon for visual representation.
     *
     * Returns emoji or icon for strategy visualization.
     *
     * @return string Unicode emoji
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::SKIP => '⏭️',      // Skip forward
            self::UPDATE => '🔄',     // Update cycle
            self::REPLACE => '🔁',    // Replace
            self::CREATE => '➕',     // Add new
            self::MERGE => '🔀',      // Merge
            self::INCREMENT => '🔢',  // Number increment
            self::ERROR => '🚫',      // Prohibited
            self::ARCHIVE => '📦',     // Archive box
        };
    }

    /**
     * Get audit log message for this strategy.
     *
     * Returns message template for audit logging.
     *
     * @return string Audit message template
     */
    public function getAuditMessage(): string
    {
        return match ($this) {
            self::SKIP => 'Skipped duplicate record: {identifier}',
            self::UPDATE => 'Updated existing record: {identifier}',
            self::REPLACE => 'Replaced existing record: {identifier}',
            self::CREATE => 'Created new record despite duplicate: {identifier}',
            self::MERGE => 'Merged data into existing record: {identifier}',
            self::INCREMENT => 'Created variant with new identifier: {identifier}',
            self::ERROR => 'Import failed due to duplicate: {identifier}',
            self::ARCHIVE => 'Archived {identifier} and created new record',
        };
    }

    /**
     * Get strategies suitable for upsert operations.
     *
     * Returns strategies that perform insert-or-update logic.
     *
     * @return array<self> Upsert-compatible strategies
     */
    public static function upsertStrategies(): array
    {
        return [
            self::UPDATE,
            self::REPLACE,
            self::MERGE,
        ];
    }

    /**
     * Get strategies that maintain data history.
     *
     * Returns strategies that preserve historical data.
     *
     * @return array<self> History-preserving strategies
     */
    public static function historyPreserving(): array
    {
        return [
            self::SKIP,
            self::ARCHIVE,
            self::INCREMENT,
        ];
    }

    /**
     * Get safe strategies for production imports.
     *
     * Returns strategies considered safe for production use.
     *
     * @return array<self> Production-safe strategies
     */
    public static function productionSafe(): array
    {
        return [
            self::SKIP,
            self::UPDATE,
            self::MERGE,
            self::ERROR,
        ];
    }
}
