<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\UpdateTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Updates editable state and labels without mutating source-authoritative structure.
 */
final readonly class UpdateTemplateAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private TemplateContentGuard $guard,
        private TranslationWriter $translations,
    ) {}

    /**
     * Update one stored template using its exact optimistic revision.
     */
    public function execute(
        Template|string $template,
        UpdateTemplateData $data,
        TemplateActorData $actor,
        TranslationSyncMode $translationMode = TranslationSyncMode::Replace,
    ): Template {
        $templateId = $template instanceof Template ? $template->id : $template;
        $this->authorization->authorize(
            TemplateAbility::Update,
            $actor,
            ['template_id' => $templateId],
        );
        $this->guard->metadata($data->metadata);

        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use (
                $actor,
                $data,
                $templateId,
                $translationMode,
            ): Template {
                $template = Template::query()->lockForUpdate()->findOrFail($templateId);

                if ($template->revision !== $data->expectedRevision) {
                    throw StaleTemplateException::forResource('template', $template->id);
                }

                $template->fill([
                    'status' => $data->status,
                    'metadata' => $data->metadata,
                ])->save();
                $this->translations->sync($template, $data->translations, $translationMode);
                TemplateChanged::dispatch($template->id, 'updated', $actor);

                return $template->refresh()->load('translations');
            });
    }
}
