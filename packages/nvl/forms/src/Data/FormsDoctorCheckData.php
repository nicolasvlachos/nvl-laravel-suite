<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable Forms installation diagnostic.
 */
#[TypeScript]
final class FormsDoctorCheckData extends Data
{
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[LiteralTypeScriptType("'error' | 'warning'")]
        public readonly string $severity,
        public readonly bool $passed,
        #[LiteralTypeScriptType('string')]
        public readonly string $message,
    ) {}
}
