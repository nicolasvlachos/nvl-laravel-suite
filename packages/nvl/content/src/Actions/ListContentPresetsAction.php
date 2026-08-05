<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Collection;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Contracts\ContentFieldPreset;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentFieldDefinitionData;
use Nvl\Content\Data\ContentFieldPresetData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Services\ContentFieldPresetRegistry;
use Nvl\Content\Services\ContentJsonSchemaBuilder;
use Nvl\Content\Services\ContentSchemaCompiler;

/**
 * Lists the reusable semantic schemas available to an authorized headless content editor.
 */
final readonly class ListContentPresetsAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentFieldPresetRegistry $presets,
        private ContentSchemaCompiler $compiler,
        private ContentJsonSchemaBuilder $jsonSchemas,
    ) {}

    /**
     * Return every registered semantic field preset in deterministic order.
     *
     * @return Collection<int, ContentFieldPresetData>
     */
    public function execute(ContentActorData $actor): Collection
    {
        $this->authorization->authorize(
            ContentAbility::ListDefinitions,
            $actor,
            context: ['presets' => true],
        );

        return collect($this->presets->all())
            ->map(function (ContentFieldPreset $preset): ContentFieldPresetData {
                $field = $this->compiler->compilePreset($preset);

                return new ContentFieldPresetData(
                    alias: $preset->alias(),
                    name: $preset->name(),
                    description: $preset->description(),
                    field: ContentFieldDefinitionData::fromDefinition($field),
                    jsonSchema: $this->jsonSchemas->field($field),
                );
            })
            ->values();
    }
}
