<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use DateTimeInterface;
use Nvl\Content\Data\ContentCompositionSnapshotData;
use Nvl\Data\Traits\DataTransform;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Models\TemplateVersion;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged, transport-safe template version representation.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TemplateVersionData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $templateId,
        public readonly int $version,
        public readonly TemplateVersionStatus $status,
        public readonly int $revision,
        public readonly ?string $contentHash,
        public readonly int $contentBlockCount,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata,
        public readonly ?string $publishedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public static function fromModel(TemplateVersion $version): self
    {
        $snapshot = $version->getAttribute('content_snapshot');

        return new self(
            id: $version->id,
            templateId: $version->template_id,
            version: $version->version,
            status: $version->status,
            revision: $version->revision,
            contentHash: $version->content_hash,
            contentBlockCount: $snapshot instanceof ContentCompositionSnapshotData
                ? count($snapshot->blocks)
                : 0,
            metadata: is_array($version->metadata) ? $version->metadata : [],
            publishedAt: self::timestamp($version->getAttribute('published_at')),
            createdAt: self::timestamp($version->getAttribute('created_at')) ?? '',
            updatedAt: self::timestamp($version->getAttribute('updated_at')) ?? '',
        );
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
