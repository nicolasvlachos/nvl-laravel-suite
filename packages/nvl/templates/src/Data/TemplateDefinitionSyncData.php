<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;

/**
 * Describes one deterministic source-definition synchronization operation.
 */
final class TemplateDefinitionSyncData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $key,
        public readonly string $operation,
    ) {}
}
