<?php

declare(strict_types=1);

namespace Nvl\Templates\Rendering;

use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Template as RenderableTemplate;

/**
 * Carries one fully validated stored-template resolution into rendering or queueing.
 */
final readonly class ResolvedStoredTemplateRender
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Template $template,
        public ?TemplateAssignment $assignment,
        public TemplateVersion $version,
        public RenderableTemplate $renderable,
        public string $locale,
        public array $payload,
    ) {}
}
