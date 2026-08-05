<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\CreateTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Creates a translated stored template from its source-authoritative definition.
 */
final readonly class CreateTemplateAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private TemplateDefinitionRegistry $definitions,
        private TemplateContentGuard $guard,
        private TranslationWriter $translations,
    ) {}

    /**
     * Create one stored template and its localized management metadata.
     */
    public function execute(CreateTemplateData $data, TemplateActorData $actor): Template
    {
        $this->authorization->authorize(TemplateAbility::Create, $actor, ['key' => $data->key]);
        $definition = $this->definitions->get($data->key);
        $this->guard->metadata($data->metadata);

        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use ($actor, $data, $definition): Template {
                $template = Template::query()->create([
                    'key' => $data->key,
                    'renderer' => $definition->renderer,
                    'status' => $data->status,
                    'schema' => $definition->schema,
                    'metadata' => $data->metadata,
                ]);
                $this->translations->replace($template, $data->translations);
                $template->load('translations');
                TemplateChanged::dispatch($template->id, 'created', $actor);

                return $template;
            });
    }
}
