<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Display, styling and behavioral options for public form rendering.
 *
 * Stored as JSON in the `options` column of the forms table.
 * All properties are optional — absent means "use platform default".
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
class FormOptions extends Data
{
    use DataTransform;

    /**
     * @param  bool  $showHeader  Whether to show the form title and intro text
     * @param  bool  $showLogo  Whether to show the platform logo/branding
     * @param  string|null  $submitButtonLabel  Custom submit button text (overrides translation)
     * @param  string|null  $successRedirectUrl  Redirect URL after successful submission (instead of thank-you page)
     * @param  string|null  $theme  Visual theme: light, dark, brand
     * @param  string|null  $maxWidth  Max card width: sm, md, lg, xl, 2xl
     * @param  string|null  $backgroundColor  Custom background color (hex or Tailwind token)
     * @param  string|null  $accentColor  Custom accent color for buttons and links (hex or Tailwind token)
     * @param  bool  $showPoweredBy  Whether to show "Powered by" footer badge
     * @param  bool  $compactLayout  Use reduced spacing and padding
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $showHeader = true,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $showLogo = false,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $submitButtonLabel = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $successRedirectUrl = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $theme = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $maxWidth = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $backgroundColor = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $accentColor = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $showPoweredBy = true,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $compactLayout = false,
    ) {}
}
