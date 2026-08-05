<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\CreateTemplateVersionData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Creates the next immutable numbered draft under a template lock.
 */
final readonly class CreateTemplateVersionAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private TemplateContentGuard $guard,
    ) {}

    public function execute(
        Template|string $template,
        CreateTemplateVersionData $data,
        TemplateActorData $actor,
    ): TemplateVersion {
        $templateId = $template instanceof Template ? $template->id : $template;
        $this->authorization->authorize(
            TemplateAbility::Update,
            $actor,
            ['template_id' => $templateId],
        );

        $this->guard->metadata($data->metadata);

        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use ($actor, $data, $templateId): TemplateVersion {
                $template = Template::query()->lockForUpdate()->findOrFail($templateId);
                $currentVersion = $template->versions()->max('version');
                $nextVersion = is_numeric($currentVersion) ? ((int) $currentVersion) + 1 : 1;
                $version = $template->versions()->create([
                    'version' => $nextVersion,
                    'metadata' => $data->metadata,
                ]);
                TemplateChanged::dispatch($template->id, 'version_created', $actor);

                return $version;
            });
    }
}
