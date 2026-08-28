<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Authorized bounded health projection for one filtered translation catalog.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TranslationCatalogStatisticsData extends Data
{
    use DataTransform;

    public readonly int $total;

    public readonly int $missing;

    public readonly int $conflicts;

    public readonly int $changed;

    /** @var array<string, int> */
    #[LiteralTypeScriptType('Record<string, number>')]
    public readonly array $locales;

    /** @var array<string, int> */
    #[LiteralTypeScriptType('Record<string, number>')]
    public readonly array $scopes;

    /**
     * Create a translation catalog statistics projection.
     *
     * @param  array<string, int>  $locales  Entry counts by locale
     * @param  array<string, int>  $scopes  Entry counts by canonical scope token
     */
    public function __construct(
        int $total,
        int $missing,
        int $conflicts,
        int $changed,
        array $locales,
        array $scopes,
    ) {
        $this->total = $total;
        $this->missing = $missing;
        $this->conflicts = $conflicts;
        $this->changed = $changed;
        $this->locales = self::normalizeDimension($locales);
        $this->scopes = self::normalizeDimension($scopes);
    }

    /**
     * Transform JSON-facing aggregate maps as objects even for sequential numeric locale keys.
     *
     * @return array<string, mixed>
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        $transformed = parent::transform($transformationContext);
        $transformed['locales'] = (object) ($transformed['locales'] ?? []);
        $transformed['scopes'] = (object) ($transformed['scopes'] ?? []);

        return $transformed;
    }

    /**
     * Retain the package's PHP array-map contract for direct consumers.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::transform();
    }

    /**
     * Normalize a bounded aggregate dimension by count descending and key ascending.
     *
     * @param  array<string, int>  $dimension
     * @return array<string, int>
     */
    private static function normalizeDimension(array $dimension): array
    {
        $normalized = [];

        foreach ($dimension as $key => $count) {
            $normalizedKey = trim((string) $key);
            $normalizedKey = $normalizedKey !== '' ? $normalizedKey : 'unknown';
            $normalized[$normalizedKey] = ($normalized[$normalizedKey] ?? 0) + max(0, $count);
        }

        uksort($normalized, static function (string $left, string $right) use ($normalized): int {
            $countComparison = $normalized[$right] <=> $normalized[$left];

            return $countComparison !== 0 ? $countComparison : strcmp($left, $right);
        });

        return array_slice($normalized, 0, 100, true);
    }
}
