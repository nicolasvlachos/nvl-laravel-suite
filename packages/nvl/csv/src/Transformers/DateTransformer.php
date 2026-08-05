<?php

declare(strict_types=1);

namespace Nvl\Csv\Transformers;

use Carbon\Carbon;
use DateTimeInterface;
use Exception;
use Stringable;

/**
 * Comprehensive date transformation utilities for CSV data processing.
 *
 * Provides flexible date parsing, formatting, and manipulation capabilities:
 * - Support for various input date formats
 * - Timezone conversion and standardization
 * - Date arithmetic (add/subtract days, months, years)
 * - Time-of-day adjustments (start/end of day)
 * - Robust error handling with configurable defaults
 */
final class DateTransformer extends CSVTransformer
{
    private ?string $inputFormat = null;

    private string $outputFormat = 'Y-m-d';

    private ?string $timezone = null;

    private ?string $defaultValue = null;

    private bool $calculateAge = false;

    private bool $startOfDay = false;

    private bool $endOfDay = false;

    private ?int $addDays = null;

    private ?int $addMonths = null;

    private ?int $addYears = null;

    /**
     * Set the expected input date format for parsing.
     *
     * Specifies the exact format of incoming date strings using PHP date format codes.
     * When not set, Carbon's intelligent parsing attempts to detect the format.
     * Setting a specific format improves performance and ensures consistency.
     *
     * @param  string  $format  PHP date format string (e.g., 'Y-m-d', 'd/m/Y H:i:s')
     * @return self Fluent interface for method chaining
     */
    public function inputFormat(string $format): self
    {
        $this->inputFormat = $format;

        return $this;
    }

    /**
     * Set the desired output date format for transformed values.
     *
     * Controls how dates are formatted in the final output using PHP date format codes.
     * Common formats include ISO 8601, database-friendly, or user-friendly formats.
     *
     * @param  string  $format  PHP date format string for output
     * @return self Fluent interface for method chaining
     */
    public function outputFormat(string $format): self
    {
        $this->outputFormat = $format;

        return $this;
    }

    /**
     * Set the target timezone for date conversion.
     *
     * Converts dates to the specified timezone during transformation.
     * Useful for standardizing dates from different regions or
     * converting to application's default timezone.
     *
     * @param  string  $timezone  Valid timezone identifier (e.g., 'UTC', 'America/New_York')
     * @return self Fluent interface for method chaining
     */
    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * Set the fallback value for invalid or unparseable dates.
     *
     * When date parsing fails, this value will be returned instead of throwing an exception.
     * Useful for handling malformed data gracefully during CSV imports.
     *
     * @param  string|null  $value  Default value to return for invalid dates
     * @return self Fluent interface for method chaining
     */
    public function default(?string $value): self
    {
        $this->defaultValue = $value;

        return $this;
    }

    /**
     * Configure to set time to start of day (00:00:00).
     *
     * Forces the time component to midnight, useful for date-only operations
     * or when you need consistent daily timestamps. Mutually exclusive with endOfDay().
     *
     * @param  bool  $start  Whether to apply start-of-day transformation
     * @return self Fluent interface for method chaining
     */
    public function startOfDay(bool $start = true): self
    {
        $this->startOfDay = $start;
        $this->endOfDay = false;

        return $this;
    }

    /**
     * Configure to set time to end of day (23:59:59).
     *
     * Forces the time component to the last second of the day, useful for
     * date range operations or daily cutoff calculations. Mutually exclusive with startOfDay().
     *
     * @param  bool  $end  Whether to apply end-of-day transformation
     * @return self Fluent interface for method chaining
     */
    public function endOfDay(bool $end = true): self
    {
        $this->endOfDay = $end;
        $this->startOfDay = false;

        return $this;
    }

    /**
     * Add or subtract days from the parsed date.
     *
     * Performs date arithmetic by adding the specified number of days.
     * Use negative values to subtract days. Applied after parsing but
     * before timezone and time-of-day adjustments.
     *
     * @param  int  $days  Number of days to add (negative to subtract)
     * @return self Fluent interface for method chaining
     */
    public function addDays(int $days): self
    {
        $this->addDays = $days;

        return $this;
    }

    /**
     * Add or subtract months from the parsed date.
     *
     * Performs date arithmetic by adding the specified number of months.
     * Handles month-end dates intelligently (e.g., Jan 31 + 1 month = Feb 28/29).
     * Use negative values to subtract months.
     *
     * @param  int  $months  Number of months to add (negative to subtract)
     * @return self Fluent interface for method chaining
     */
    public function addMonths(int $months): self
    {
        $this->addMonths = $months;

        return $this;
    }

    /**
     * Add or subtract years from the parsed date.
     *
     * Performs date arithmetic by adding the specified number of years.
     * Handles leap years appropriately (Feb 29 + 1 year may become Feb 28).
     * Use negative values to subtract years.
     *
     * @param  int  $years  Number of years to add (negative to subtract)
     * @return self Fluent interface for method chaining
     */
    public function addYears(int $years): self
    {
        $this->addYears = $years;

        return $this;
    }

    /**
     * Transform a raw value into a formatted date string.
     *
     * Performs the complete date transformation pipeline:
     * 1. Handle null/empty values using default
     * 2. Parse input using specified format or intelligent parsing
     * 3. Apply timezone conversion if configured
     * 4. Apply start/end of day adjustments
     * 5. Apply date arithmetic (add days/months/years)
     * 6. Format output according to specified format
     *
     * @param  mixed  $value  Raw date value from CSV
     * @param  array<string, mixed>  $context  Additional transformation context
     * @return mixed Transformed date string or default value
     */
    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $this->defaultValue;
        }

        try {
            // Parse date using specified format or intelligent parsing
            if ($this->inputFormat !== null) {
                if (! is_scalar($value) && ! $value instanceof Stringable) {
                    return $this->defaultValue;
                }

                $format = str_contains($this->inputFormat, '!') || str_contains($this->inputFormat, '|')
                    ? $this->inputFormat
                    : '!'.$this->inputFormat;
                $date = Carbon::createFromFormat($format, (string) $value);
                $parseErrors = Carbon::getLastErrors();
                if (
                    $date === null
                    || ($parseErrors !== false
                        && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
                ) {
                    return $this->defaultValue;
                }
            } else {
                if (
                    ! is_string($value)
                    && ! is_int($value)
                    && ! is_float($value)
                    && ! $value instanceof DateTimeInterface
                ) {
                    return $this->defaultValue;
                }

                $date = Carbon::parse($value);
            }

            if ($this->calculateAge) {
                return $date->age;
            }

            // Apply timezone conversion if configured
            if ($this->timezone !== null) {
                $date->setTimezone($this->timezone);
            }

            // Apply time-of-day adjustments (mutually exclusive)
            if ($this->startOfDay) {
                $date->startOfDay(); // Set to 00:00:00
            } elseif ($this->endOfDay) {
                $date->endOfDay();   // Set to 23:59:59
            }

            // Apply date arithmetic in order
            if ($this->addDays !== null) {
                $date->addDays($this->addDays);
            }

            if ($this->addMonths !== null) {
                $date->addMonths($this->addMonths);
            }

            if ($this->addYears !== null) {
                $date->addYears($this->addYears);
            }

            // Format output using configured format
            return $date->format($this->outputFormat);
        } catch (Exception) {
            return $this->defaultValue;
        }
    }

    /**
     * Create a date transformer with specific input and output formats.
     *
     * Factory method for creating a transformer that converts dates from
     * one format to another. Most common use case for standardizing date formats.
     *
     * @param  string  $inputFormat  Expected input date format
     * @param  string  $outputFormat  Desired output date format
     * @return self Configured transformer instance
     */
    public static function format(string $inputFormat, string $outputFormat = 'Y-m-d'): self
    {
        return (new self)
            ->inputFormat($inputFormat)
            ->outputFormat($outputFormat);
    }

    /**
     * Create a transformer that outputs ISO 8601 datetime format.
     *
     * Produces standardized ISO datetime strings suitable for APIs,
     * databases, and international data exchange.
     *
     * @return self Transformer configured for ISO 8601 output
     */
    public static function iso(): self
    {
        return (new self)
            ->timezone('UTC')
            ->outputFormat('Y-m-d\TH:i:s\Z');
    }

    /**
     * Create a transformer that outputs Unix timestamps.
     *
     * Converts dates to Unix timestamp format (seconds since epoch).
     * Useful for systems that work with numeric timestamps.
     *
     * @return self Transformer configured for Unix timestamp output
     */
    public static function timestamp(): self
    {
        return (new self)
            ->outputFormat('U');
    }

    /**
     * Create a transformer that calculates age from birth dates.
     *
     * Converts birth dates to current age in years. Returns null for
     * invalid dates. Useful for demographic data processing.
     *
     * @return self Transformer configured for age calculation
     */
    public static function age(): self
    {
        $transformer = new self;
        $transformer->calculateAge = true;

        return $transformer;
    }
}
