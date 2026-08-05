<?php

declare(strict_types=1);

namespace Nvl\Templates\Tests\Fixtures;

use Nvl\Templates\Contracts\TemplateRenderer;
use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Rendering\TemplateRenderContext;

/**
 * Deterministic renderer used by package isolation tests.
 */
final class TestTemplateRenderer implements TemplateRenderer
{
    /**
     * Render deterministic text from a Content composition and template data.
     */
    public function render(TemplateRenderContext $context): RenderedTemplateData
    {
        $text = $context->composition()?->value('body.text');
        $name = $context->data()['name'] ?? '';
        $content = (is_string($text) ? $text : '').':'.
            (is_string($name) ? $name : '');

        return new RenderedTemplateData(
            content: $content,
            mimeType: 'text/plain',
            renderer: $context->renderer,
            byteSize: strlen($content),
            checksum: hash('sha256', $content),
            subject: $context->subject,
        );
    }
}
