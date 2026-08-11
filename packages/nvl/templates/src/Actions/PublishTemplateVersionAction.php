<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Content;
use Nvl\Content\Data\ContentCompositionSnapshotBlockData;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Data\TemplateDefinitionData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Services\CanonicalJson;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Publishes a complete version and retires its previously published sibling.
 */
final readonly class PublishTemplateVersionAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private TemplateDefinitionRegistry $definitions,
        private Content $content,
    ) {}

    /**
     * Publish one synchronized draft and retire its published predecessor.
     */
    public function execute(
        TemplateVersion|string $version,
        int $expectedRevision,
        TemplateActorData $actor,
    ): TemplateVersion {
        $versionId = $version instanceof TemplateVersion ? $version->id : $version;
        $this->authorization->authorize(
            TemplateAbility::Publish,
            $actor,
            ['version_id' => $versionId],
        );

        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use ($actor, $expectedRevision, $versionId): TemplateVersion {
                $version = TemplateVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($versionId);
                $template = Template::query()
                    ->lockForUpdate()
                    ->findOrFail($version->template_id);
                $version->setRelation('template', $template);

                if ($version->revision !== $expectedRevision) {
                    throw StaleTemplateException::forResource('template version', $version->id);
                }

                if ($version->status !== TemplateVersionStatus::Draft) {
                    throw new InvalidArgumentException(
                        'Only draft template versions can be published.',
                    );
                }

                $definition = $this->definitions->get($template->key);
                $canonicalJson = new CanonicalJson;

                if ($template->status !== TemplateStatus::Active
                    || $template->renderer !== $definition->renderer
                    || $canonicalJson->digest($template->schema)
                        !== $canonicalJson->digest($definition->schema)) {
                    throw new InvalidArgumentException(
                        "Template [{$template->key}] must be active and synchronized before publication.",
                    );
                }

                $snapshot = $this->content->capture(
                    $version,
                    TemplateVersion::CONTENT_GROUP,
                    $actor->contentActor(),
                    publishing: true,
                );
                $this->assertComposition($snapshot->blocks, $definition);

                $publishedVersions = TemplateVersion::query()
                    ->where('template_id', $version->template_id)
                    ->where('id', '!=', $version->id)
                    ->where('status', TemplateVersionStatus::Published->value)
                    ->lockForUpdate()
                    ->get();

                foreach ($publishedVersions as $publishedVersion) {
                    $publishedVersion->fill([
                        'status' => TemplateVersionStatus::Retired,
                    ])->save();
                }

                $version->fill([
                    'status' => TemplateVersionStatus::Published,
                    'content_snapshot' => $snapshot,
                    'content_hash' => $snapshot->version,
                    'published_by_type' => $actor->type,
                    'published_by' => $actor->id,
                    'published_at' => now(),
                ])->save();
                TemplateChanged::dispatch($version->template_id, 'version_published', $actor);

                return $version->refresh();
            });
    }

    /**
     * @param  list<ContentCompositionSnapshotBlockData>  $blocks
     */
    private function assertComposition(
        array $blocks,
        TemplateDefinitionData $definition,
    ): void {
        if ($blocks === []) {
            throw new InvalidArgumentException(
                'A template version cannot be published without Content block placements.',
            );
        }

        $regions = [];

        foreach ($blocks as $block) {
            $regions[$block->region] = true;

            if ($definition->allowedContentDefinitions !== []
                && ! in_array(
                    $block->definitionKey,
                    $definition->allowedContentDefinitions,
                    true,
                )) {
                throw new InvalidArgumentException(
                    "Content definition [{$block->definitionKey}] is not allowed in template [{$definition->key}].",
                );
            }
        }

        foreach ($definition->requiredRegions as $region) {
            if (! isset($regions[$region])) {
                throw new InvalidArgumentException(
                    "Template [{$definition->key}] requires Content region [{$region}].",
                );
            }
        }
    }
}
