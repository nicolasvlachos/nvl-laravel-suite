<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Models\Template;

/**
 * Loads a complete management aggregate after Action authorization.
 */
final readonly class GetTemplateAction
{
    public function __construct(private TemplateAuthorization $authorization) {}

    public function execute(Template|string $template, TemplateActorData $actor): Template
    {
        $templateId = $template instanceof Template ? $template->id : $template;
        $model = Template::query()
            ->with([
                'translations',
                'versions',
                'assignments',
            ])
            ->findOrFail($templateId);
        $this->authorization->authorize(
            TemplateAbility::View,
            $actor,
            ['template_id' => $model->id],
        );

        return $model;
    }
}
