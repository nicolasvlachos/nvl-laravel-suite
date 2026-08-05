<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use InvalidArgumentException;
use Nvl\Seo\Enums\StructuredDataType;
use Nvl\Seo\Support\HttpUrl;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Describes one context-free schema.org node returned by a resource provider.
 */
#[TypeScript]
final class StructuredDataNodeData extends Data
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $id = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $properties = [],
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9]*$/', $this->type) !== 1) {
            throw new InvalidArgumentException("Invalid schema.org type [{$this->type}].");
        }

        if ($this->id !== null
            && ! HttpUrl::isAbsolute($this->id)
            && preg_match('/^(?:#|urn:)[^\s]+$/', $this->id) !== 1) {
            throw new InvalidArgumentException(
                'Structured-data node identifiers must be absolute HTTP URLs, fragments, or URNs.',
            );
        }
    }

    /**
     * Create a node from a built-in or custom schema.org type.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function make(
        string|StructuredDataType $type,
        array $properties = [],
        ?string $id = null,
    ): self {
        return new self(
            type: $type instanceof StructuredDataType ? $type->value : $type,
            id: $id,
            properties: $properties,
        );
    }

    /**
     * Convert the node into a context-free JSON-LD object.
     *
     * @return array<string, mixed>
     */
    public function toJsonLd(): array
    {
        $properties = $this->properties;
        unset($properties['@context'], $properties['@type'], $properties['@id']);

        return array_filter([
            ...$properties,
            '@type' => $this->type,
            '@id' => $this->id,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
