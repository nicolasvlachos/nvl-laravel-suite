<?php

declare(strict_types=1);

namespace Nvl\Templates\Rendering;

use Illuminate\Contracts\View\Factory;
use Nvl\Templates\Contracts\TemplateRenderer;
use Nvl\Templates\Data\RenderedTemplateData;

/**
 * Renders a source-controlled Blade view with a bounded data context.
 */
final readonly class BladeTemplateRenderer implements TemplateRenderer
{
    public function __construct(private Factory $views) {}

    /**
     * Render one template view as UTF-8 HTML.
     */
    public function render(TemplateRenderContext $context): RenderedTemplateData
    {
        $content = $this->views
            ->make($context->view, $context->viewData())
            ->render();

        return new RenderedTemplateData(
            content: $content,
            mimeType: 'text/html; charset=UTF-8',
            renderer: $context->renderer,
            byteSize: strlen($content),
            checksum: hash('sha256', $content),
            subject: $context->subject,
            suggestedFilename: $context->filename
                ?? "{$context->template->key}-{$context->locale}.html",
        );
    }
}
