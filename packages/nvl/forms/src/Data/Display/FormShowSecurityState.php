<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Derived security and availability state for a form show page.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormShowSecurityState extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var string Yes label from shared translations
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $yesLabel,

        /**
         * @var string No label from shared translations
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $noLabel,

        /**
         * @var bool Whether public access is restricted
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isRestricted,

        /**
         * @var FormShowBadge Restrict public access badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $restrictPublicAccess,

        /**
         * @var FormShowBadge Allow multiple registrations badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $allowMultipleRegistrations,

        /**
         * @var FormShowBadge Honeypot enabled badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $enableHoneypot,

        /**
         * @var FormShowBadge CSRF required badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $requireCsrf,

        /**
         * @var FormShowBadge Rate limiting enabled badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $enableRateLimiting,

        /**
         * @var FormShowBadge Date restricted badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $dateRestricted,

        /**
         * @var FormShowBadge Current availability badge
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowBadge::class)]
        public readonly FormShowBadge $availability,

        /**
         * @var string Resolved rate limit value display
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $rateLimitPerHourDisplay,

        /**
         * @var string Resolved resolvement label
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $resolvementLabel,

        /**
         * @var string Resolved form type label
         */
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string')]
        public readonly string $typeLabel,
    ) {}
}
