<?php

declare(strict_types=1);

namespace Nvl\Suite\Support;

/**
 * A value-free node extracted from a literal PHP configuration array.
 */
final readonly class SuiteConfigurationNode
{
    /**
     * @param  'array'|'list'|'map'|'scalar'  $kind
     * @param  array<string, self>  $children
     */
    public function __construct(
        public string $kind,
        public array $children = [],
        public bool $unavailable = false,
    ) {}
}
