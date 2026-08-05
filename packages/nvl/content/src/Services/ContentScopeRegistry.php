<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Data\ContentDefinitionData;
use Nvl\Content\Support\ContentArrays;

/**
 * Validates block scopes and their opaque keys without hardcoded tenants or sites.
 */
final class ContentScopeRegistry
{
    /**
     * @param  list<string>  $aliases
     */
    public function assertRegistered(array $aliases): void
    {
        $scopes = $this->configured();

        foreach ($aliases as $alias) {
            if (! isset($scopes[$alias])) {
                throw new InvalidArgumentException(
                    "Content scope [{$alias}] is not registered.",
                );
            }
        }
    }

    public function assert(
        string $scope,
        string $scopeKey,
        ContentDefinitionData $definition,
    ): void {
        if (! in_array($scope, $definition->allowedScopes, true)) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] is unavailable in scope [{$scope}].",
            );
        }

        $scopes = $this->configured();

        if (! isset($scopes[$scope])) {
            throw new InvalidArgumentException("Content scope [{$scope}] is not registered.");
        }

        $pattern = $scopes[$scope]['key_pattern'] ?? null;

        if (! is_string($pattern) || @preg_match($pattern, $scopeKey) !== 1) {
            throw new InvalidArgumentException(
                "Content scope key [{$scopeKey}] is invalid for [{$scope}].",
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configured(): array
    {
        $configured = config('content.scopes', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('content.scopes must be an array.');
        }

        $scopes = [];

        foreach ($configured as $alias => $settings) {
            if (! is_string($alias)
                || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $alias) !== 1
                || ! is_array($settings)) {
                throw new InvalidArgumentException('Every configured content scope is invalid.');
            }

            $pattern = $settings['key_pattern'] ?? null;

            if (! is_string($pattern) || @preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(
                    "Content scope [{$alias}] requires a valid key_pattern.",
                );
            }

            $scopes[$alias] = ContentArrays::stringMap(
                $settings,
                "content scope {$alias}",
            );
        }

        return $scopes;
    }
}
