<?php

declare(strict_types=1);

namespace Nvl\Forms\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Root derived display-state payload for the forms show page.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class FormShowStates extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var FormShowStatusState Resolved status state
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowStatusState::class)]
        public readonly FormShowStatusState $status,

        /**
         * @var FormShowSecurityState Resolved security/availability state
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowSecurityState::class)]
        public readonly FormShowSecurityState $security,

        /**
         * @var FormShowLinkState Resolved public/embed links
         */
        #[TypeScriptOptional]
        #[TypeScriptType(FormShowLinkState::class)]
        public readonly FormShowLinkState $links,

        /**
         * @var DataCollection<int, FormShowStat> Stats cards payload
         */
        #[TypeScriptOptional]
        #[DataCollectionOf(FormShowStat::class)]
        public readonly DataCollection $stats,
    ) {}
}
