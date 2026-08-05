<?php

declare(strict_types=1);

namespace Nvl\Templates\Contracts;

use Nvl\Templates\Data\RenderedTemplateData;
use Nvl\Templates\Rendering\TemplateRenderContext;

/**
 * Renders a resolved template through an explicitly registered driver.
 */
interface TemplateRenderer
{
    /**
     * Render one fully validated template context.
     */
    public function render(TemplateRenderContext $context): RenderedTemplateData;
}
