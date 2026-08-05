<?php

declare(strict_types=1);

namespace Nvl\Csv\Transformers;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

/**
 * String transformation utilities for CSV data.
 */
final class StringTransformer extends CSVTransformer
{
    public const CASE_LOWER = 'lower';

    public const CASE_UPPER = 'upper';

    public const CASE_TITLE = 'title';

    public const CASE_CAMEL = 'camel';

    public const CASE_SNAKE = 'snake';

    public const CASE_KEBAB = 'kebab';

    private ?string $caseType = null;

    private bool $trim = false;

    private ?string $prefix = null;

    private ?string $suffix = null;

    private ?int $maxLength = null;

    private string $truncateSuffix = '...';

    /** @var array<string, string> */
    private array $replacements = [];

    private ?string $defaultValue = null;

    /**
     * Set case transformation.
     *
     * @param  string  $case  Case type to apply
     * @return self Transformer instance
     */
    public function toCase(string $case): self
    {
        if (! in_array($case, [
            self::CASE_LOWER,
            self::CASE_UPPER,
            self::CASE_TITLE,
            self::CASE_CAMEL,
            self::CASE_SNAKE,
            self::CASE_KEBAB,
        ], true)) {
            throw new InvalidArgumentException("Unsupported string case '{$case}'.");
        }

        $this->caseType = $case;

        return $this;
    }

    /**
     * Enable trimming.
     *
     * @param  bool  $trim  Toggle trimming
     * @return self Transformer instance
     */
    public function trim(bool $trim = true): self
    {
        $this->trim = $trim;

        return $this;
    }

    /**
     * Add prefix.
     *
     * @param  string  $prefix  Prefix to prepend
     * @return self Transformer instance
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Add suffix.
     *
     * @param  string  $suffix  Suffix to append
     * @return self Transformer instance
     */
    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Set max length with truncation.
     *
     * @param  int  $length  Maximum length
     * @param  string  $suffix  Truncation suffix
     * @return self Transformer instance
     */
    public function maxLength(int $length, string $suffix = '...'): self
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Maximum length cannot be negative.');
        }

        $this->maxLength = $length;
        $this->truncateSuffix = $suffix;

        return $this;
    }

    /**
     * Add string replacement.
     *
     * @param  string  $search  Search value
     * @param  string  $replace  Replacement value
     * @return self Transformer instance
     */
    public function replace(string $search, string $replace): self
    {
        $this->replacements[$search] = $replace;

        return $this;
    }

    /**
     * Set default value for empty strings.
     *
     * @param  string  $value  Default value
     * @return self Transformer instance
     */
    public function default(string $value): self
    {
        $this->defaultValue = $value;

        return $this;
    }

    /**
     * Transform value.
     *
     * @param  mixed  $value  Input value
     * @param  array<string, mixed>  $context  Transformation context
     * @return mixed Transformed value
     */
    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $this->defaultValue ?? $value;
        }

        if (! is_scalar($value) && ! $value instanceof Stringable) {
            throw new InvalidArgumentException('String transformation requires a scalar or stringable value.');
        }

        $result = (string) $value;

        // Apply trimming
        if ($this->trim) {
            $result = trim($result);
        }

        // Apply replacements
        foreach ($this->replacements as $search => $replace) {
            $result = str_replace($search, $replace, $result);
        }

        // Apply case transformation
        if ($this->caseType !== null) {
            $result = $this->applyCase($result);
        }

        // Apply prefix
        if ($this->prefix !== null) {
            $result = $this->prefix.$result;
        }

        // Apply suffix
        if ($this->suffix !== null) {
            $result .= $this->suffix;
        }

        // Apply max length
        if ($this->maxLength !== null && mb_strlen($result) > $this->maxLength) {
            $truncateLength = max(0, $this->maxLength - mb_strlen($this->truncateSuffix));
            $result = mb_substr($result, 0, $truncateLength).$this->truncateSuffix;
            $result = mb_substr($result, 0, $this->maxLength);
        }

        return $result;
    }

    /**
     * Apply case transformation.
     *
     * @param  string  $value  Input value
     * @return string Transformed value
     */
    private function applyCase(string $value): string
    {
        return match ($this->caseType) {
            self::CASE_LOWER => mb_strtolower($value),
            self::CASE_UPPER => mb_strtoupper($value),
            self::CASE_TITLE => mb_convert_case($value, MB_CASE_TITLE),
            self::CASE_CAMEL => $this->toCamelCase($value),
            self::CASE_SNAKE => $this->toSnakeCase($value),
            self::CASE_KEBAB => $this->toKebabCase($value),
            default => $value,
        };
    }

    /**
     * Convert to camelCase.
     *
     * @param  string  $value  Input value
     * @return string camelCase value
     */
    private function toCamelCase(string $value): string
    {
        return Str::camel($value);
    }

    /**
     * Convert to snake_case.
     *
     * @param  string  $value  Input value
     * @return string snake_case value
     */
    private function toSnakeCase(string $value): string
    {
        return Str::snake($value);
    }

    /**
     * Convert to kebab-case.
     *
     * @param  string  $value  Input value
     * @return string kebab-case value
     */
    private function toKebabCase(string $value): string
    {
        return preg_replace('/-+/', '-', Str::kebab($value)) ?? Str::kebab($value);
    }

    /**
     * Create lowercase transformer.
     *
     * @return self Transformer instance
     */
    public static function lowercase(): self
    {
        return (new self)->toCase(self::CASE_LOWER)->trim();
    }

    /**
     * Create uppercase transformer.
     *
     * @return self Transformer instance
     */
    public static function uppercase(): self
    {
        return (new self)->toCase(self::CASE_UPPER)->trim();
    }

    /**
     * Create slug transformer.
     *
     * @return self Transformer instance
     */
    public static function slug(): self
    {
        return (new self)
            ->toCase(self::CASE_KEBAB)
            ->trim()
            ->replace(' ', '-')
            ->replace('_', '-')
            ->replace('--', '-');
    }

    /**
     * Create sanitizer transformer.
     *
     * @return self Transformer instance
     */
    public static function sanitize(): self
    {
        return (new self)
            ->trim()
            ->replace("\n", ' ')
            ->replace("\r", ' ')
            ->replace("\t", ' ');
    }
}
