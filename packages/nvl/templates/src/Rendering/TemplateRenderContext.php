<?php

declare(strict_types=1);

namespace Nvl\Templates\Rendering;

use Nvl\Content\Data\RenderedContentBlockData;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Templates\Data\PdfOptions;
use Nvl\Templates\Template;

/**
 * Provides renderers and Blade views with one fully validated immutable context.
 */
final readonly class TemplateRenderContext
{
    /**
     * @param  array<string, mixed>  $rendererOptions
     */
    public function __construct(
        public Template $template,
        public string $view,
        public string $renderer,
        public string $locale,
        public ?string $subject,
        public ?string $filename,
        public ?PdfOptions $pdf,
        public array $rendererOptions,
    ) {}

    /**
     * Return the optional Content composition.
     */
    public function composition(): ?RenderedContentCompositionData
    {
        return $this->template->composition;
    }

    /**
     * Return the template data object.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->template->data;
    }

    /**
     * Return implementation-layer settings supplied to the template.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->template->settings;
    }

    /**
     * Return the root Content blocks or an empty list.
     *
     * @return list<RenderedContentBlockData>
     */
    public function blocks(): array
    {
        $composition = $this->composition();

        return $composition === null ? [] : $composition->blocks;
    }

    /**
     * Return Content blocks grouped by region or an empty map.
     *
     * @return array<string, list<RenderedContentBlockData>>
     */
    public function regions(): array
    {
        $composition = $this->composition();

        return $composition === null ? [] : $composition->regions;
    }

    /**
     * Return the explicit variables made available to a Blade template.
     *
     * @return array{
     *     template: Template,
     *     options: self,
     *     composition: RenderedContentCompositionData|null,
     *     blocks: list<RenderedContentBlockData>,
     *     regions: array<string, list<RenderedContentBlockData>>,
     *     data: array<string, mixed>,
     *     settings: array<string, mixed>
     * }
     */
    public function viewData(): array
    {
        return [
            'template' => $this->template,
            'options' => $this,
            'composition' => $this->composition(),
            'blocks' => $this->blocks(),
            'regions' => $this->regions(),
            'data' => $this->data(),
            'settings' => $this->settings(),
        ];
    }
}
