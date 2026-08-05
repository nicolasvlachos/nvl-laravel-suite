<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use DateTimeInterface;
use Nvl\Data\Traits\DataTransform;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Models\TemplateVersion;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged template aggregate without raw Eloquent serialization.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TemplateManagementData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array{title: string, description: string|null}>  $translations
     * @param  list<TemplateVersionData>  $versions
     * @param  list<TemplateAssignmentData>  $assignments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $renderer,
        public readonly TemplateStatus $status,
        public readonly int $revision,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $schema,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata,
        #[LiteralTypeScriptType('Record<string, { title: string; description: string | null }>')]
        public readonly array $translations,
        public readonly array $versions,
        public readonly array $assignments,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public static function fromModel(Template $template): self
    {
        $template->loadMissing('translations');
        $translations = [];

        foreach ($template->translations as $translation) {
            $translations[$translation->locale] = [
                'title' => $translation->title,
                'description' => $translation->description,
            ];
        }
        $versions = $template->relationLoaded('versions')
            ? $template->versions->map(
                static fn (TemplateVersion $version): TemplateVersionData => TemplateVersionData::fromModel(
                    $version,
                ),
            )->values()->all()
            : [];
        $assignments = $template->relationLoaded('assignments')
            ? $template->assignments->map(
                static fn (TemplateAssignment $assignment): TemplateAssignmentData => TemplateAssignmentData::fromModel(
                    $assignment,
                ),
            )->values()->all()
            : [];

        return new self(
            id: $template->id,
            key: $template->key,
            renderer: $template->renderer,
            status: $template->status,
            revision: $template->revision,
            schema: is_array($template->schema) ? $template->schema : [],
            metadata: is_array($template->metadata) ? $template->metadata : [],
            translations: $translations,
            versions: array_values($versions),
            assignments: array_values($assignments),
            createdAt: self::timestamp($template->getAttribute('created_at')),
            updatedAt: self::timestamp($template->getAttribute('updated_at')),
        );
    }

    private static function timestamp(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : '';
    }
}
