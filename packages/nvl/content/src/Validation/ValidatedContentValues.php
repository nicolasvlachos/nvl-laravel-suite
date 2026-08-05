<?php

declare(strict_types=1);

namespace Nvl\Content\Validation;

/**
 * Normalized base and localized values returned by the validation boundary.
 */
final readonly class ValidatedContentValues
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function __construct(
        public array $values,
        public array $translations,
    ) {}
}
