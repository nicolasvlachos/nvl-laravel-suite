<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentFieldDefinitionValidator;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Services\ContentReferenceRegistry;
use Nvl\Content\Support\ContentConfiguration;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Validates opaque IDs through an allowlisted application resolver.
 */
final class ReferenceFieldTypeAdapter extends AbstractFieldTypeAdapter implements ContentFieldDefinitionValidator
{
    public function __construct(
        private readonly bool $multiple,
        private readonly ContentReferenceRegistry $references,
    ) {}

    public function alias(): string
    {
        return $this->multiple ? 'reference_list' : 'reference';
    }

    public function validateDefinition(ContentFieldDefinition $field): void
    {
        $alias = $field->setting('reference_type');

        if (! is_string($alias) || trim($alias) === '') {
            throw new InvalidArgumentException(
                "Content field [{$field->key}] requires reference_type.",
            );
        }

        $this->references->assertRegistered($alias);
    }

    /**
     * @return string|list<string>|null
     */
    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): string|array|null {
        if ($value === null) {
            return null;
        }

        $alias = $field->setting('reference_type');

        if (! is_string($alias) || trim($alias) === '') {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] requires reference_type.",
            );
        }

        $ids = $this->multiple ? $value : [$value];

        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] must contain reference IDs.",
            );
        }

        $maximum = $field->setting(
            'max_items',
            ContentConfiguration::positiveInteger('content.validation.maximum_items', 500),
        );

        if (! is_int($maximum) || count($ids) > $maximum) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] contains too many references.",
            );
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (! is_string($id)
                || preg_match('/^[^\x00-\x1F\x7F]{1,191}$/u', $id) !== 1) {
                throw new InvalidArgumentException(
                    "Content field [{$context->path}] contains an invalid reference ID.",
                );
            }

            if ($context->resolveExternal) {
                $this->references->assertExists($alias, $id, $context);
            }

            $normalized[] = $id;
        }

        $normalized = array_values(array_unique($normalized));

        return $this->multiple ? $normalized : ($normalized[0] ?? null);
    }

    public function render(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed {
        if ($value === null) {
            return null;
        }

        $alias = $field->setting('reference_type');

        if (! is_string($alias)) {
            return null;
        }

        $ids = $this->multiple ? $value : [$value];

        if (! is_array($ids)) {
            return null;
        }

        $rendered = [];

        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }

            $resolve = fn (): ?array => $this->references->display(
                $alias,
                $id,
                $context,
            );
            $display = $context->resources !== null
                ? $context->resources->reference(
                    $this->resourceCacheKey($alias, $id, $context),
                    $resolve,
                )
                : $resolve();

            if ($display !== null) {
                $rendered[] = ['id' => $id, ...$display];
            }
        }

        return $this->multiple ? $rendered : ($rendered[0] ?? null);
    }

    private function resourceCacheKey(
        string $alias,
        string $identifier,
        ContentValidationContext $context,
    ): string {
        $ownerId = $context->owner?->getKey();

        if (! is_int($ownerId) && ! is_string($ownerId)) {
            $ownerId = null;
        }

        return hash('sha256', json_encode([
            'alias' => $alias,
            'identifier' => $identifier,
            'locale' => $context->locale,
            'actor' => [
                'type' => $context->actor->type,
                'id' => $context->actor->id,
                'system' => $context->actor->system,
            ],
            'visibility' => $context->visibility->value,
            'path' => $context->path,
            'publishing' => $context->publishing,
            'owner' => $context->owner === null ? null : [
                'type' => $context->owner->getMorphClass(),
                'id' => $ownerId,
            ],
            'public_only' => $context->publicOnly,
            'group' => $context->group,
            'localized' => $context->localized,
            'resolve_external' => $context->resolveExternal,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
