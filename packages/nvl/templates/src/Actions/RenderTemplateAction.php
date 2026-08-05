<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Services\TemplateContextFactory;
use Nvl\Templates\Services\TemplateOutputGuard;
use Nvl\Templates\Services\TemplateRendererRegistry;
use Nvl\Templates\Template;

/**
 * Renders one code-defined Template through its configured implementation.
 */
final readonly class RenderTemplateAction
{
    public function __construct(
        private TemplateContextFactory $contexts,
        private TemplateRendererRegistry $renderers,
        private TemplateOutputGuard $outputGuard,
    ) {}

    /**
     * Render the supplied Template and return verified output bytes.
     */
    public function execute(Template $template): RenderedTemplateData
    {
        $context = $this->contexts->make($template);
        $rendered = $this->renderers->get($context->renderer)->render($context);
        $this->outputGuard->validate($context, $rendered);

        return $rendered;
    }
}
