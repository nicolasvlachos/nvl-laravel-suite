<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Carbon\CarbonImmutable;
use Nvl\Data\Traits\DataTransform;
use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Models\TranslationEntry;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Data transfer object for a translation entry row.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class TranslationEntryPayload extends Data
{
    use DataTransform;

    /**
     * @param  string  $id  Entry id
     * @param  TranslationScopeType  $scopeType  Scope type
     * @param  string  $scopeName  Scope name
     * @param  string  $locale  Locale code
     * @param  TranslationFormat  $format  Translation format
     * @param  string|null  $group  Group key for PHP translations
     * @param  string  $key  Entry key
     * @param  string|null  $value  Translation text value
     * @param  bool  $isMissing  Source file missing marker
     * @param  CarbonImmutable|null  $lastImportedAt  Last import timestamp
     * @param  CarbonImmutable  $createdAt  Creation timestamp
     * @param  CarbonImmutable  $updatedAt  Last update timestamp
     * @param  array<string, mixed>|null  $conflictMetadata  Synchronization conflict details
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $id,
        #[TypeScriptType(TranslationScopeType::class)]
        public readonly TranslationScopeType $scopeType,
        #[LiteralTypeScriptType('string')]
        public readonly string $scopeName,
        #[LiteralTypeScriptType('string')]
        public readonly string $locale,
        #[TypeScriptType(TranslationFormat::class)]
        public readonly TranslationFormat $format,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $group,
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $value,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isMissing,
        #[LiteralTypeScriptType('number')]
        public readonly int $revision,
        #[TypeScriptType(TranslationSyncStatus::class)]
        public readonly TranslationSyncStatus $syncStatus,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $conflictMetadata,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?CarbonImmutable $lastImportedAt,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?CarbonImmutable $lastExportedAt,
        #[LiteralTypeScriptType('string')]
        public readonly CarbonImmutable $createdAt,
        #[LiteralTypeScriptType('string')]
        public readonly CarbonImmutable $updatedAt,
    ) {}

    /**
     * Build DTO from model.
     */
    public static function fromModel(TranslationEntry $entry): self
    {
        return new self(
            id: $entry->id,
            scopeType: TranslationScopeType::from($entry->scope_type),
            scopeName: $entry->scope_name,
            locale: $entry->locale,
            format: TranslationFormat::from($entry->format),
            group: $entry->group === '*' ? null : $entry->group,
            key: $entry->key,
            value: $entry->value,
            isMissing: $entry->is_missing,
            revision: $entry->revision,
            syncStatus: $entry->sync_status,
            conflictMetadata: $entry->conflict_metadata,
            lastImportedAt: $entry->last_imported_at?->toImmutable(),
            lastExportedAt: $entry->last_exported_at?->toImmutable(),
            createdAt: $entry->created_at?->toImmutable() ?? CarbonImmutable::now(),
            updatedAt: $entry->updated_at?->toImmutable() ?? CarbonImmutable::now(),
        );
    }
}
