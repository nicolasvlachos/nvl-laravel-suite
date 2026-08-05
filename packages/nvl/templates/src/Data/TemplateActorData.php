<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Content\Data\ContentActorData;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transport-neutral actor identity used by authorization and audit events.
 */
#[TypeScript]
final class TemplateActorData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $id = null,
        public readonly bool $system = false,
    ) {}

    public static function system(): self
    {
        return new self(system: true);
    }

    /**
     * Adapt this identity to the Content authorization boundary.
     */
    public function contentActor(): ContentActorData
    {
        return new ContentActorData($this->type, $this->id, $this->system);
    }
}
