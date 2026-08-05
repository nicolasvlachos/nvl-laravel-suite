<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentDefinition;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentIdentityGuard;
use Nvl\Content\Services\ContentMediaSynchronizer;
use Nvl\Content\Services\ContentPatch;
use Nvl\Content\Services\ContentPayloadGuard;
use Nvl\Content\Services\ContentRevisionRecorder;
use Nvl\Content\Services\ContentScopeRegistry;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Validation\ContentValueValidator;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Creates a draft block and all localized/media state atomically.
 */
final readonly class CreateContentBlockAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentDefinitionRegistry $definitions,
        private ContentScopeRegistry $scopes,
        private ContentValueValidator $validator,
        private ContentMediaSynchronizer $media,
        private ContentRevisionRecorder $revisions,
        private CanonicalJson $json,
        private ContentPayloadGuard $guard,
        private ContentPatch $patch,
        private ContentIdentityGuard $identities,
        private TranslationWriter $translations,
    ) {}

    public function execute(
        CreateContentBlockData $data,
        ContentActorData $actor,
    ): ContentBlock {
        $this->authorization->authorize(
            ContentAbility::Create,
            $actor,
            context: [
                'definition' => $data->definition,
                'key' => $data->key,
                'scope' => $data->scope,
                'scope_key' => $data->scopeKey,
                'visibility' => $data->visibility->value,
                'status' => ContentStatus::Draft->value,
            ],
        );
        $this->identities->blockKey($data->key);
        $definition = $this->definitions->get($data->definition);
        $this->scopes->assert($data->scope, $data->scopeKey, $definition);
        $inputValues = ContentArrays::stringMap($data->values, 'content block values');
        $inputTranslations = ContentArrays::translations(
            $data->translations,
            'content block translations',
        );
        $metadata = ContentArrays::stringMap($data->metadata, 'content block metadata');
        $modelDefinition = ContentDefinition::query()
            ->where('key', $definition->key)
            ->where('is_active', true)
            ->whereNull('orphaned_at')
            ->first();

        if (! $modelDefinition instanceof ContentDefinition) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] is not synchronized and active.",
            );
        }

        $definitionHash = $this->json->hash($definition->toArray());

        if (! hash_equals($modelDefinition->source_hash, $definitionHash)) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] is stale; synchronize definitions first.",
            );
        }

        $values = $this->patch->merge($definition->defaults, $inputValues);
        $schema = $definition->schema->toSchema();
        $validated = $this->validator->validate(
            $schema,
            $values,
            $inputTranslations,
            $actor,
            $data->visibility,
        );
        $this->guard->metadata($metadata);

        return DB::connection($modelDefinition->getConnectionName())
            ->transaction(function () use (
                $actor,
                $data,
                $definition,
                $definitionHash,
                $metadata,
                $modelDefinition,
                $schema,
                $validated,
            ): ContentBlock {
                $lockedDefinition = ContentDefinition::query()
                    ->whereKey($modelDefinition->id)
                    ->where('is_active', true)
                    ->whereNull('orphaned_at')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedDefinition instanceof ContentDefinition) {
                    throw new InvalidArgumentException(
                        "Content definition [{$definition->key}] is not synchronized and active.",
                    );
                }

                if (! hash_equals($lockedDefinition->source_hash, $definitionHash)) {
                    throw new InvalidArgumentException(
                        "Content definition [{$definition->key}] is stale; synchronize definitions first.",
                    );
                }

                $block = ContentBlock::query()->create([
                    'definition_id' => $lockedDefinition->id,
                    'key' => $data->key,
                    'scope' => $data->scope,
                    'scope_key' => $data->scopeKey,
                    'visibility' => $data->visibility,
                    'values' => $validated->values,
                    'metadata' => $metadata === [] ? null : $metadata,
                    'definition_version' => $definition->version,
                    'definition_hash' => $definitionHash,
                    'definition_schema' => $schema,
                    'definition_view' => $definition->view,
                    'created_by_type' => $actor->type,
                    'created_by_id' => $actor->id,
                    'updated_by_type' => $actor->type,
                    'updated_by_id' => $actor->id,
                ]);
                $this->translations->replace(
                    $block,
                    $this->translationPayloads($validated->translations),
                );
                $this->media->synchronize(
                    $block,
                    $schema,
                    $validated->values,
                    $validated->translations,
                    $actor,
                );
                $this->revisions->record($block, ContentRevisionEvent::Created, $actor);
                ContentBlockChanged::dispatch(
                    $block->id,
                    ContentRevisionEvent::Created,
                    $block->revision,
                    $actor,
                );

                return $block->load(['definition', 'translations']);
            });
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array{values: array<string, mixed>}>
     */
    private function translationPayloads(array $translations): array
    {
        $payloads = [];

        foreach ($translations as $locale => $values) {
            $payloads[$locale] = ['values' => $values];
        }

        return $payloads;
    }
}
