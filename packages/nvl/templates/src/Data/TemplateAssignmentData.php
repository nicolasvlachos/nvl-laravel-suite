<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Templates\Models\TemplateAssignment;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged owner assignment representation.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TemplateAssignmentData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $id,
        public readonly string $templateId,
        public readonly ?string $templateVersionId,
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $profile,
        public readonly int $revision,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $settings,
    ) {}

    public static function fromModel(TemplateAssignment $assignment): self
    {
        return new self(
            id: $assignment->id,
            templateId: $assignment->template_id,
            templateVersionId: $assignment->template_version_id,
            ownerType: $assignment->owner_type,
            ownerId: $assignment->owner_id,
            profile: $assignment->profile,
            revision: $assignment->revision,
            settings: is_array($assignment->settings) ? $assignment->settings : [],
        );
    }
}
