<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Contracts\Translation\Translator;
use InvalidArgumentException;
use Nvl\Templates\Data\TemplateOptions;
use Nvl\Templates\Rendering\TemplateRenderContext;
use Nvl\Templates\Support\TemplatesConfiguration;
use Nvl\Templates\Template;

/**
 * Resolves application defaults and per-template options into a safe render context.
 */
final readonly class TemplateOptionsResolver
{
    public function __construct(
        private Translator $translator,
        private TemplateContentGuard $contentGuard,
        private TemplateLocaleResolver $locales,
    ) {}

    /**
     * Resolve and validate options for one template.
     */
    public function resolve(Template $template): TemplateRenderContext
    {
        $options = $template->options ?? new TemplateOptions;
        $this->contentGuard->rendererOptions($options->rendererOptions);
        $renderer = $options->renderer
            ?? TemplatesConfiguration::string('templates.default_renderer', 'blade');
        $this->assertAlias($renderer, 'renderer');
        $view = $template->view !== ''
            ? $template->view
            : $this->defaultView($renderer);
        $this->assertView($view);
        $locale = $this->locales->resolve(
            $options->locale
                ?? config('templates.default_locale')
                ?? $this->translator->getLocale(),
        );
        $subject = $this->nullableString($options->subject, 'subject', 998, false);
        $filename = $this->filename($options->filename);

        if ($template->composition !== null
            && $this->locales->resolve($template->composition->locale) !== $locale) {
            throw new InvalidArgumentException(
                'The Content composition locale must match the template locale.',
            );
        }

        return new TemplateRenderContext(
            template: $template,
            view: $view,
            renderer: $renderer,
            locale: $locale,
            subject: $subject,
            filename: $filename,
            pdf: $options->pdf,
            rendererOptions: $options->rendererOptions,
        );
    }

    private function assertAlias(string $value, string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Template {$key} [{$value}] must be a stable lowercase alias.",
            );
        }
    }

    private function assertView(string $view): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\\/-]*$/', $view) !== 1
            || str_contains($view, '..')
            || str_starts_with($view, '/')) {
            throw new InvalidArgumentException("Template view [{$view}] is invalid.");
        }
    }

    private function defaultView(string $renderer): string
    {
        $view = config("templates.views.defaults.{$renderer}");

        if (! is_string($view) || trim($view) === '') {
            throw new InvalidArgumentException(
                "Template renderer [{$renderer}] requires an explicit view or configured default.",
            );
        }

        return $view;
    }

    private function nullableString(
        mixed $value,
        string $key,
        int $maximum,
        bool $allowLines = true,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)
            || mb_strlen($value) > $maximum
            || (! $allowLines && preg_match('/[\\r\\n]/', $value) === 1)) {
            throw new InvalidArgumentException(
                "Template {$key} must be a string of at most {$maximum} characters.",
            );
        }

        return $value;
    }

    private function filename(mixed $value): ?string
    {
        $filename = $this->nullableString($value, 'filename', 191, false);

        if ($filename !== null
            && (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1
                || str_contains($filename, '..'))) {
            throw new InvalidArgumentException(
                'Template filename must be a safe basename without a path.',
            );
        }

        return $filename;
    }
}
