<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Data\ContentSchemaData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Validation\ContentSchemaValidator;
use Nvl\Content\Validation\ContentValueValidator;

/**
 * In-memory source of truth for available block definitions.
 */
final class ContentDefinitionRegistry
{
    /** @var array<string, ContentDefinitionData> */
    private array $definitions = [];

    public function __construct(
        private readonly ContentSchemaValidator $validator,
        private readonly ContentScopeRegistry $scopes,
        private readonly ContentSchemaCompiler $compiler,
        private readonly ContentJsonSchemaBuilder $jsonSchemas,
        private readonly ContentValueValidator $values,
    ) {}

    public function register(ContentDefinitionSource $source): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,190}$/', $source->key) !== 1) {
            throw new InvalidArgumentException(
                "Content definition key [{$source->key}] is invalid.",
            );
        }

        if (isset($this->definitions[$source->key])) {
            throw new InvalidArgumentException(
                "Content definition [{$source->key}] is already registered.",
            );
        }

        if ($source->version < 1 || $source->sortOrder < 0) {
            throw new InvalidArgumentException(
                "Content definition [{$source->key}] has invalid version or order values.",
            );
        }

        $schema = $this->compiler->compile($source->schema);
        $definition = new ContentDefinitionData(
            key: $source->key,
            name: $source->name,
            description: $source->description,
            category: $source->category,
            version: $source->version,
            view: $source->view,
            schema: ContentSchemaData::fromSchema($schema),
            defaults: $source->defaults,
            allowedScopes: $source->allowedScopes,
            allowedRegions: $source->allowedRegions,
            isActive: $source->isActive,
            sortOrder: $source->sortOrder,
            jsonSchema: $this->jsonSchemas->definition(
                $source->key,
                $source->version,
                $schema,
            ),
        );
        $this->assertMetadata($definition);
        $this->validator->validate($schema);
        $this->values->assertDefaults($schema);
        $this->values->validate(
            schema: $schema,
            values: $definition->defaults,
            translations: [],
            actor: ContentActorData::system(),
            visibility: ContentVisibility::Private,
            resolveExternal: false,
        );
        ContentArrays::stringMap(
            $definition->defaults,
            "content definition {$definition->key} defaults",
        );
        $this->assertAliases($definition->allowedScopes, 'scope', $definition->key);
        $this->assertAliases($definition->allowedRegions, 'region', $definition->key);
        $this->scopes->assertRegistered($definition->allowedScopes);
        $this->definitions[$definition->key] = $definition;
        ksort($this->definitions);
    }

    public function get(string $key): ContentDefinitionData
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException(
                "Content definition [{$key}] is not registered.",
            );
    }

    /**
     * @return list<ContentDefinitionData>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @param  list<string>  $aliases
     */
    private function assertAliases(array $aliases, string $kind, string $definition): void
    {
        if ($aliases === []) {
            throw new InvalidArgumentException(
                "Content definition [{$definition}] requires at least one {$kind}.",
            );
        }

        foreach ($aliases as $alias) {
            if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1) {
                throw new InvalidArgumentException(
                    "Content definition [{$definition}] contains invalid {$kind} [{$alias}].",
                );
            }
        }

        if (count($aliases) !== count(array_unique($aliases))) {
            throw new InvalidArgumentException(
                "Content definition [{$definition}] contains duplicate {$kind} aliases.",
            );
        }
    }

    private function assertMetadata(ContentDefinitionData $definition): void
    {
        if (trim($definition->name) === '' || mb_strlen($definition->name) > 191) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] has an invalid name.",
            );
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $definition->category) !== 1) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] has an invalid category.",
            );
        }

        if ($definition->description !== null
            && strlen($definition->description) > 65_000) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] description is too large.",
            );
        }

        if ($definition->view !== null
            && (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,254}$/', $definition->view) !== 1
                || str_contains($definition->view, '..')
                || str_contains($definition->view, '//'))) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] has an invalid view name.",
            );
        }
    }
}
