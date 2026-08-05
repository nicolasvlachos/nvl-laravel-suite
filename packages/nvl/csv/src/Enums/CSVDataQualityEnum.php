<?php

declare(strict_types=1);

namespace Nvl\Csv\Enums;

/**
 * Data quality assessment levels for CSV file analysis.
 *
 * Provides standardized quality metrics for CSV data evaluation:
 * - Assess overall data validity and completeness
 * - Guide import strategy decisions
 * - Set quality thresholds for automated processing
 * - Generate quality reports and recommendations
 */
enum CSVDataQualityEnum: string
{
    // Quality levels from best to worst
    case EXCELLENT = 'excellent';   // 95%+ valid data
    case GOOD = 'good';             // 80-95% valid data
    case FAIR = 'fair';             // 60-80% valid data
    case POOR = 'poor';             // 40-60% valid data
    case CRITICAL = 'critical';     // <40% valid data

    /**
     * Get the minimum validity percentage for this quality level.
     *
     * Returns the lower bound of valid data percentage
     * required to achieve this quality rating.
     *
     * @return float Minimum validity percentage (0-100)
     */
    public function getValidityThreshold(): float
    {
        return match ($this) {
            self::EXCELLENT => 95.0,
            self::GOOD => 80.0,
            self::FAIR => 60.0,
            self::POOR => 40.0,
            self::CRITICAL => 0.0,
        };
    }

    /**
     * Get the validity percentage range for this quality level.
     *
     * Returns the min and max percentages that fall within
     * this quality classification.
     *
     * @return array{min: float, max: float} Validity range
     */
    public function getValidityRange(): array
    {
        return match ($this) {
            self::EXCELLENT => ['min' => 95.0, 'max' => 100.0],
            self::GOOD => ['min' => 80.0, 'max' => 95.0],
            self::FAIR => ['min' => 60.0, 'max' => 80.0],
            self::POOR => ['min' => 40.0, 'max' => 60.0],
            self::CRITICAL => ['min' => 0.0, 'max' => 40.0],
        };
    }

    /**
     * Check if this quality level requires manual review.
     *
     * Poor and critical quality typically need human intervention
     * before automated processing.
     *
     * @return bool True if manual review is recommended
     */
    public function requiresManualReview(): bool
    {
        return match ($this) {
            self::POOR, self::CRITICAL => true,
            default => false,
        };
    }

    /**
     * Check if automated processing is recommended.
     *
     * High quality data can be safely processed automatically.
     *
     * @return bool True if automated processing is safe
     */
    public function canAutoProcess(): bool
    {
        return match ($this) {
            self::EXCELLENT, self::GOOD => true,
            self::FAIR => true, // With warnings
            default => false,
        };
    }

    /**
     * Get recommended import strategy for this quality level.
     *
     * Suggests the best approach for handling data at this quality level.
     *
     * @return string Import strategy recommendation
     */
    public function getImportStrategy(): string
    {
        return match ($this) {
            self::EXCELLENT => 'bulk_import',      // Fast bulk import
            self::GOOD => 'validated_import',      // Import with validation
            self::FAIR => 'cautious_import',       // Row-by-row with recovery
            self::POOR => 'manual_review',         // Review before import
            self::CRITICAL => 'reject',            // Do not import
        };
    }

    /**
     * Get quality score multiplier for weighted calculations.
     *
     * Used to weight quality scores in aggregate calculations.
     *
     * @return float Score multiplier (0.0-1.0)
     */
    public function getScoreMultiplier(): float
    {
        return match ($this) {
            self::EXCELLENT => 1.0,
            self::GOOD => 0.9,
            self::FAIR => 0.7,
            self::POOR => 0.4,
            self::CRITICAL => 0.1,
        };
    }

    /**
     * Get confidence level for data reliability.
     *
     * Indicates how confident we can be in the data's accuracy.
     *
     * @return string Confidence level description
     */
    public function getConfidenceLevel(): string
    {
        return match ($this) {
            self::EXCELLENT => 'very_high',
            self::GOOD => 'high',
            self::FAIR => 'moderate',
            self::POOR => 'low',
            self::CRITICAL => 'very_low',
        };
    }

    /**
     * Get color for visual representation.
     *
     * Returns color suitable for quality badges and charts.
     *
     * @return string Color identifier
     */
    public function getColor(): string
    {
        return match ($this) {
            self::EXCELLENT => 'emerald',   // Best quality
            self::GOOD => 'green',          // Good quality
            self::FAIR => 'yellow',         // Acceptable
            self::POOR => 'orange',         // Concerning
            self::CRITICAL => 'red',        // Unacceptable
        };
    }

    /**
     * Get icon for quality visualization.
     *
     * Returns emoji or icon representing quality level.
     *
     * @return string Unicode emoji
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::EXCELLENT => '⭐',     // Star for excellence
            self::GOOD => '✅',         // Check for good
            self::FAIR => '⚠️',         // Warning for fair
            self::POOR => '⚡',         // Lightning for poor
            self::CRITICAL => '💥',      // Explosion for critical
        };
    }

    /**
     * Get user-friendly label for this quality level.
     *
     * Provides human-readable quality labels for UI display.
     *
     * @return string Display label
     */
    public function label(): string
    {
        return match ($this) {
            self::EXCELLENT => 'Excellent Quality',
            self::GOOD => 'Good Quality',
            self::FAIR => 'Fair Quality',
            self::POOR => 'Poor Quality',
            self::CRITICAL => 'Critical Issues',
        };
    }

    /**
     * Get detailed description of this quality level.
     *
     * Explains what this quality level means for the data.
     *
     * @return string Quality description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::EXCELLENT => 'Data is nearly perfect with minimal issues',
            self::GOOD => 'Data is high quality with minor issues',
            self::FAIR => 'Data has moderate issues but is usable',
            self::POOR => 'Data has significant issues requiring attention',
            self::CRITICAL => 'Data has severe issues and may be unusable',
        };
    }

    /**
     * Get recommended actions for this quality level.
     *
     * Provides actionable recommendations based on quality.
     *
     * @return array<string> List of recommended actions
     */
    public function getRecommendedActions(): array
    {
        return match ($this) {
            self::EXCELLENT => [
                'Proceed with import',
                'Use bulk operations for speed',
            ],
            self::GOOD => [
                'Proceed with import',
                'Enable validation checks',
                'Log any warnings',
            ],
            self::FAIR => [
                'Review sample data',
                'Enable strict validation',
                'Process in smaller batches',
                'Prepare error recovery',
            ],
            self::POOR => [
                'Manual data review required',
                'Identify and fix common issues',
                'Consider data cleansing',
                'Process with caution',
            ],
            self::CRITICAL => [
                'Do not import automatically',
                'Investigate data source',
                'Request new data export',
                'Manual intervention required',
            ],
        };
    }

    /**
     * Calculate quality level from validity score.
     *
     * Determines appropriate quality enum based on percentage
     * of valid data in the CSV file.
     *
     * @param  float  $validityPercentage  Percentage of valid data (0-100)
     * @return self Corresponding quality level
     */
    public static function fromScore(float $validityPercentage): self
    {
        return match (true) {
            $validityPercentage >= 95.0 => self::EXCELLENT,
            $validityPercentage >= 80.0 => self::GOOD,
            $validityPercentage >= 60.0 => self::FAIR,
            $validityPercentage >= 40.0 => self::POOR,
            default => self::CRITICAL,
        };
    }

    /**
     * Calculate aggregate quality from multiple column qualities.
     *
     * Computes overall quality based on individual column quality scores.
     *
     * @param  array<float>  $columnScores  Array of column validity percentages
     * @return self Aggregate quality level
     */
    public static function fromColumnScores(array $columnScores): self
    {
        if (empty($columnScores)) {
            return self::CRITICAL;
        }

        // Calculate weighted average
        $average = array_sum($columnScores) / count($columnScores);

        // Apply penalty for columns with critical issues
        $criticalCount = count(array_filter($columnScores, fn ($score) => $score < 40));
        if ($criticalCount > 0) {
            // Reduce average based on critical column count
            $penalty = ($criticalCount / count($columnScores)) * 20;
            $average = max(0, $average - $penalty);
        }

        return self::fromScore($average);
    }

    /**
     * Get quality levels suitable for production use.
     *
     * Returns quality levels considered safe for production.
     *
     * @return array<self> Production-ready quality levels
     */
    public static function productionReady(): array
    {
        return [
            self::EXCELLENT,
            self::GOOD,
        ];
    }

    /**
     * Get quality levels requiring intervention.
     *
     * Returns quality levels that need manual review.
     *
     * @return array<self> Intervention-required quality levels
     */
    public static function requiresIntervention(): array
    {
        return [
            self::POOR,
            self::CRITICAL,
        ];
    }
}
