<?php

declare(strict_types=1);

namespace Nvl\Content\FieldTypes;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentFieldDefinitionValidator;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Services\ContentPayloadGuard;
use Nvl\Content\Validation\ContentValidationContext;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;

/**
 * Validates JSON-compatible data against a required Draft 2020-12 schema.
 */
final class JsonFieldTypeAdapter extends AbstractFieldTypeAdapter implements ContentFieldDefinitionValidator
{
    /** @var array<string, object> */
    private array $compiledSchemas = [];

    public function __construct(
        private readonly Validator $validator,
        private readonly ContentPayloadGuard $guard,
    ) {}

    public function alias(): string
    {
        return 'json';
    }

    public function validateDefinition(ContentFieldDefinition $field): void
    {
        $schema = $field->setting('schema');

        if (! is_array($schema)) {
            throw new InvalidArgumentException(
                "JSON content field [{$field->key}] requires a schema setting.",
            );
        }

        if (! (bool) config('content.validation.json_schema.allow_remote_references', false)
            && $this->containsRemoteReference($schema)) {
            throw new InvalidArgumentException(
                "JSON Schema for [{$field->key}] contains a remote reference.",
            );
        }

        $this->compiledSchema($schema, $field->key);
    }

    public function normalize(
        mixed $value,
        ContentFieldDefinition $field,
        ContentValidationContext $context,
    ): mixed {
        if ($value === null) {
            return null;
        }

        $this->guard->json($value, "Content JSON field [{$context->path}]");
        $encoded = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $schema = $field->setting('schema');

        if (! is_array($schema)) {
            throw new InvalidArgumentException(
                "JSON Schema for [{$context->path}] must be an object.",
            );
        }

        if (! (bool) config('content.validation.json_schema.allow_remote_references', false)
            && $this->containsRemoteReference($schema)) {
            throw new InvalidArgumentException(
                "JSON Schema for [{$context->path}] contains a remote reference.",
            );
        }

        $dataObject = json_decode($encoded, false, flags: JSON_THROW_ON_ERROR);
        $schemaObject = $this->compiledSchema($schema, $context->path);

        $result = $this->validator->validate($dataObject, $schemaObject);

        if (! $result->isValid()) {
            throw new InvalidArgumentException(
                "Content field [{$context->path}] does not satisfy its JSON Schema: ".
                (string) $result,
            );
        }

        return json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private function compiledSchema(array $schema, string $path): object
    {
        $encoded = json_encode($schema, JSON_THROW_ON_ERROR);
        $key = hash('sha256', $encoded);

        if (isset($this->compiledSchemas[$key])) {
            return $this->compiledSchemas[$key];
        }

        $schemaObject = json_decode(
            $encoded,
            false,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_object($schemaObject)) {
            throw new InvalidArgumentException(
                "JSON Schema for [{$path}] must decode to an object.",
            );
        }

        try {
            $this->validator->validate(null, $schemaObject);
        } catch (SchemaException $exception) {
            throw new InvalidArgumentException(
                "JSON Schema for [{$path}] is invalid: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $this->compiledSchemas[$key] = $schemaObject;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function containsRemoteReference(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true)
                && is_string($item)
                && ! str_starts_with($item, '#')) {
                return true;
            }

            if (is_array($item) && $this->containsRemoteReference($item)) {
                return true;
            }
        }

        return false;
    }
}
