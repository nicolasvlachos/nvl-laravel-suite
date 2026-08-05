<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Contracts\ContentDefinitionMigration;

/**
 * Holds the single canonical sequential migration chain for each definition.
 */
final class ContentDefinitionMigrationRegistry
{
    /** @var array<string, array<int, ContentDefinitionMigration>> */
    private array $migrations = [];

    public function register(ContentDefinitionMigration $migration): void
    {
        $definition = $migration->definitionKey();
        $from = $migration->fromVersion();
        $to = $migration->toVersion();

        if (preg_match('/^[a-z][a-z0-9_.-]{0,190}$/', $definition) !== 1) {
            throw new InvalidArgumentException(
                "Content definition migration key [{$definition}] is invalid.",
            );
        }

        if ($from < 1 || $to !== $from + 1) {
            throw new InvalidArgumentException(
                "Content definition migration [{$definition}] must advance exactly one version.",
            );
        }

        if (isset($this->migrations[$definition][$from])) {
            throw new InvalidArgumentException(
                "Content definition migration [{$definition}:{$from}->{$to}] is already registered.",
            );
        }

        $this->migrations[$definition][$from] = $migration;
        ksort($this->migrations[$definition]);
        ksort($this->migrations);
    }

    /**
     * Return every sequential step required to reach the target version.
     *
     * @return list<ContentDefinitionMigration>
     */
    public function path(string $definition, int $from, int $to): array
    {
        if ($from < 1 || $to < 1 || $from > $to) {
            throw new InvalidArgumentException(
                "Content definition migration range [{$definition}:{$from}->{$to}] is invalid.",
            );
        }

        $path = [];

        for ($version = $from; $version < $to; $version++) {
            $migration = $this->migrations[$definition][$version] ?? null;

            if (! $migration instanceof ContentDefinitionMigration) {
                throw new InvalidArgumentException(
                    "Content definition [{$definition}] is missing migration ".
                    "{$version}->".($version + 1).'.',
                );
            }

            $path[] = $migration;
        }

        return $path;
    }

    public function hasPath(string $definition, int $from, int $to): bool
    {
        if ($from < 1 || $to < 1 || $from > $to) {
            return false;
        }

        for ($version = $from; $version < $to; $version++) {
            if (! isset($this->migrations[$definition][$version])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return safe deterministic identifiers for diagnostics.
     *
     * @return list<string>
     */
    public function identifiers(): array
    {
        $identifiers = [];

        foreach ($this->migrations as $definition => $migrations) {
            foreach ($migrations as $migration) {
                $identifiers[] = "{$definition}:{$migration->fromVersion()}->{$migration->toVersion()}";
            }
        }

        return $identifiers;
    }
}
