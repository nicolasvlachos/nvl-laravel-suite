<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Contracts\View\Factory;
use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplatePayloadValidator;
use Nvl\Templates\Rendering\TemplateRenderContext;
use Nvl\Templates\Template;

/**
 * Validates one Template and creates the renderer-neutral context.
 */
final readonly class TemplateContextFactory
{
    public function __construct(
        private Factory $views,
        private TemplatePayloadValidator $payloadValidator,
        private TemplateContentGuard $contentGuard,
        private TemplateOptionsResolver $optionsResolver,
    ) {}

    /**
     * Build a safe context from one code-defined Template.
     */
    public function make(Template $template): TemplateRenderContext
    {
        $this->contentGuard->schema($template->schema);
        $this->contentGuard->data($template->data);
        $this->contentGuard->settings($template->settings);
        $this->payloadValidator->validateSchema($template->schema);
        $this->payloadValidator->validate($template->schema, $template->data);

        $context = $this->optionsResolver->resolve($template);

        if (! $this->views->exists($context->view)) {
            throw new InvalidArgumentException(
                "Template view [{$context->view}] does not exist.",
            );
        }

        return $context;
    }
}
