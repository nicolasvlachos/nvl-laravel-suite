<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Forms\Enums\CorsPolicy;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Validated CORS behavior for a form or allowed-origin override.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormCorsSettings extends Data
{
    use DataTransform;

    /**
     * @param  CorsPolicy  $policy  Named policy baseline
     * @param  bool  $allowCredentials  Whether credentialed cross-origin requests are allowed
     * @param  bool  $allowWildcards  Whether wildcard header/origin behavior is allowed
     * @param  int  $maxAge  Browser preflight cache duration in seconds
     * @param  list<string>  $allowedMethods  Allowed cross-origin methods
     * @param  list<string>  $allowedHeaders  Allowed cross-origin request headers
     */
    public function __construct(
        #[TypeScriptType(CorsPolicy::class)]
        public readonly CorsPolicy $policy = CorsPolicy::Moderate,

        #[LiteralTypeScriptType('boolean')]
        public readonly bool $allowCredentials = true,

        #[LiteralTypeScriptType('boolean')]
        public readonly bool $allowWildcards = false,

        #[LiteralTypeScriptType('number')]
        public readonly int $maxAge = 600,

        /** @var list<string> */
        #[LiteralTypeScriptType('string[]')]
        public readonly array $allowedMethods = ['GET', 'POST', 'OPTIONS'],

        /** @var list<string> */
        #[LiteralTypeScriptType('string[]')]
        public readonly array $allowedHeaders = [
            'Content-Type',
            'X-CSRF-TOKEN',
            'X-XSRF-TOKEN',
            'X-Form-Origin',
            'X-Forms-Public-Token',
            'Idempotency-Key',
        ],
    ) {}
}
