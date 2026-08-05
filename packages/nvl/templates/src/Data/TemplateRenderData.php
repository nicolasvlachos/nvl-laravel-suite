<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use DateTimeInterface;
use Nvl\Data\Traits\DataTransform;
use Nvl\Templates\Enums\TemplateRenderStatus;
use Nvl\Templates\Models\TemplateRender;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transport-safe durable render status and private output reference.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TemplateRenderData extends Data
{
    use DataTransform;

    /**
     * Create a private transport-safe render status snapshot.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $templateId,
        public readonly string $templateVersionId,
        public readonly ?string $templateAssignmentId,
        public readonly string $locale,
        public readonly string $profile,
        public readonly TemplateRenderStatus $status,
        public readonly int $attempts,
        public readonly ?string $outputName,
        public readonly ?string $outputMimeType,
        public readonly ?string $outputMediaId,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly ?string $failedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Build a transport-safe representation from a loaded render model.
     */
    public static function fromModel(TemplateRender $render): self
    {
        return new self(
            id: $render->id,
            templateId: $render->template_id,
            templateVersionId: $render->template_version_id,
            templateAssignmentId: $render->template_assignment_id,
            locale: $render->locale,
            profile: $render->profile,
            status: $render->status,
            attempts: $render->attempts,
            outputName: $render->output_name,
            outputMimeType: $render->output_mime_type,
            outputMediaId: $render->status === TemplateRenderStatus::Completed
                ? $render->getFirstMedia('output')?->id
                : null,
            startedAt: self::timestamp($render->started_at),
            completedAt: self::timestamp($render->completed_at),
            failedAt: self::timestamp($render->failed_at),
            createdAt: self::timestamp($render->created_at) ?? '',
            updatedAt: self::timestamp($render->updated_at) ?? '',
        );
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
