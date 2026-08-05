<?php

declare(strict_types=1);

namespace Nvl\Templates;

use InvalidArgumentException;
use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Templates\Data\TemplateOptions;

/**
 * Describes one source-controlled view, its bounded data, options, and optional Content composition.
 *
 * Consumer applications may instantiate this class directly or extend it for
 * domain-specific templates with typed constructor arguments.
 */
class Template
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $view = '',
        public readonly array $data = [],
        public readonly ?TemplateOptions $options = null,
        public readonly ?RenderedContentCompositionData $composition = null,
        public readonly array $schema = [],
        public readonly array $settings = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $this->key) !== 1) {
            throw new InvalidArgumentException(
                "Template key [{$this->key}] must be a stable lowercase alias.",
            );
        }

        if ($this->view !== ''
            && (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\\/-]*$/', $this->view) !== 1
                || str_contains($this->view, '..')
                || str_starts_with($this->view, '/'))) {
            throw new InvalidArgumentException(
                "Template view [{$this->view}] is invalid.",
            );
        }

        $this->ensureAssociative($this->data, 'data');
        $this->ensureAssociative($this->schema, 'schema');
        $this->ensureAssociative($this->settings, 'settings');
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function ensureAssociative(array $value, string $name): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException(
                "Template {$name} must be an associative object.",
            );
        }
    }
}
