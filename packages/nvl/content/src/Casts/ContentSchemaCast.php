<?php

declare(strict_types=1);

namespace Nvl\Content\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Content\Schema\ContentSchema;

/**
 * Persists an immutable ContentSchema as deterministic JSON.
 *
 * @implements CastsAttributes<ContentSchema, ContentSchema|array<array-key, mixed>>
 */
final class ContentSchemaCast implements CastsAttributes
{
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ContentSchema {
        $decoded = is_string($value)
            ? json_decode($value, true, flags: JSON_THROW_ON_ERROR)
            : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("The [{$key}] attribute must contain a content schema.");
        }

        return ContentSchema::fromArray($decoded);
    }

    /**
     * @return array<string, string>
     */
    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): array {
        if (! $value instanceof ContentSchema && ! is_array($value)) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a content schema.");
        }

        $schema = $value instanceof ContentSchema
            ? $value
            : ContentSchema::fromArray($value);

        return [
            $key => json_encode(
                $schema->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ];
    }
}
