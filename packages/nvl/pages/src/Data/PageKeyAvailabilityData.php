<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Consumer-safe result for a globally unique Page key availability check.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PageKeyAvailabilityData extends Data
{
    use DataTransform;

    /**
     * Create one Page key availability result.
     */
    public function __construct(
        public readonly string $site,
        public readonly string $key,
        public readonly bool $available,
        public readonly ?string $conflictingPageId,
    ) {}
}
