<?php

declare(strict_types=1);

namespace Nvl\Templates\Html;

use Nvl\Templates\Templates\BasePdfTemplate;

/**
 * Prepares a class-based template and returns its renderer-neutral HTML/CSS.
 */
final class TemplateRenderer
{
    public function render(BasePdfTemplate $template, TemplateContext $context): HtmlPayload
    {
        $template
            ->setLanguage($context->language)
            ->setData($context->data)
            ->setOptions($context->options)
            ->withFallbackLanguage($context->fallbackLanguage)
            ->variant($context->variant);

        if ($context->frameKey !== null) {
            $template->useFrame($context->frameKey);
        }

        foreach ($context->stickers as $sticker) {
            $coordinates = array_intersect_key(
                $sticker,
                array_flip(['x_mm', 'y_mm', 'w_mm', 'h_mm', 'rotate']),
            );
            $template->useStickers()->addStickerSrc($sticker['src'], $coordinates);
        }

        $rendered = $template->render();

        return new HtmlPayload($rendered['html'], $rendered['css']);
    }
}
